<?php

namespace bdk\SimpleCache\Adapters;

use APCIterator;
use APCuIterator;
use bdk\SimpleCache\Adapters\Collections\Apc as Collection;

/**
 * APC (Alternative PHP Cache) adapter.
 *
 * APC offers native support for
 *   * expiry
 *
 * @see http://php.net/manual/en/intro.apc.php
 */
class Apc extends Base
{
    /**
     * APC only deletes expired data on every new (page) request rather than
     * checking it when you actually fetch the value.
     *
     * Since it's possible to store values that expire in the samerequest,
     * we'll keep track of those expiration times here .
     *
     * @var array
     *
     * @see http://stackoverflow.com/questions/11750223/apc-user-cache-entries-not-expiring
     */
    protected $expires = array();
    protected $lockPrefix = 'lock.';

    /**
     * Constructor
     */
    public function __construct()
    {
        if (!\function_exists('apcu_fetch') && !\function_exists('apc_fetch')) {
            throw new Exception('ext-apc(u) is not installed.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $expire = $this->expiry($expire);
        $ttl = $this->ttl($expire);
        if ($ttl < 0) {
            // don't add expired already value
            // check if exists
            $this->get($key);
            return $this->lastGetInfo['code'] != 'hit';
        }
        // lock required for CAS
        if (!$this->lock($key)) {
            return false;
        }
        $success = $this->apcAdd($key, array(
            'v' => $value,
            'e' => $expire,
            // 'eo' => $expire,
            'ct' => null,
        ), $this->ttlExtend($ttl));
        $this->stashTtl($key, $ttl);
        $this->unlock($key);
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $expire = $this->expiry($expire);
        $ttl = $this->ttl($expire);
        // lock required because we can't perform an atomic CAS
        if (!$this->lock($key)) {
            return false;
        }
        $this->get($key);
        if ($token !== $this->lastGetInfo['token']) {
            $this->unlock($key);
            return false;
        }
        if ($ttl < 0) {
            // don't bother storing negative ttl
            // make sure APC treats this key as deleted
            $this->apcDelete($key);
            unset($this->expires[$key]);
            $this->unlock($key);
            return true;
        }
        $success = $this->apcStore($key, array(
            'v' => $value,
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : $expire,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null
        ), $this->ttlExtend($ttl));
        $this->stashTtl($key, $ttl);
        $this->unlock($key);
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->expires = array();
        return $this->apcClearCache();
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        // lock required for CAS
        if (!$this->lock($key)) {
            return false;
        }
        $success = $this->apcDelete($key);
        unset($this->expires[$key]);
        $this->unlock($key);
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        if (empty($keys)) {
            return array();
        }
        // attempt to get locks for all items
        $locked = $this->lock($keys);
        $failed = \array_diff($keys, $locked);
        $keys = \array_intersect($keys, $locked);
        // only delete those where lock was acquired
        if ($keys) {
            /*
                Contrary to the docs, apc_delete also accepts an array of multiple keys to be deleted.
                Docs for apcu_delete are ok in this regard.
                But both are flawed in terms of return value in this case: an array with failed keys is returned.

                @see http://php.net/manual/en/function.apc-delete.php

                @var string[]
            */
            $result = $this->apcDelete($keys);
            $failed = \array_merge($failed, $result);
            $this->unlock($keys);
        }
        $return = array();
        foreach ($keys as $key) {
            $return[$key] = !\in_array($key, $failed);
            unset($this->expires[$key]);
        }
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $token = null;
        $this->resetLastGetInfo($key);
        $data = $this->apcFetch($key, $success);
        if ($success === false) {
            return false;
        }
        // check for values that were just stored in this request but have actually expired by now
        $isExpired = false;
        if (isset($this->expires[$key]) && $this->expires[$key] < \time()) {
            $isExpired = true;
        } else {
            $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
            $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
        }
        $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
            'calcTime' => $data['ct'],
            'code' => 'hit',
            'expiry' => $data['e'],
            // 'expiryOriginal' => $data['eo'],
            'token' => \md5(\serialize($data['v'])),
        ));
        if ($isExpired) {
            $this->lastGetInfo['code'] = 'expired';
            $this->lastGetInfo['expiredValue'] = $data['v'];
            return false;
        }
        $token = $this->lastGetInfo['token'];
        return $data['v'];
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        return new Collection($this, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $tokens = array();
        if (empty($keys)) {
            return array();
        }
        // check for values that were just stored in this request but have
        // actually expired by now
        foreach ($keys as $i => $key) {
            if (isset($this->expires[$key]) && $this->expires[$key] < \time()) {
                unset($keys[$i]);
            }
        }
        $values = $this->apcFetch($keys) ?: array();
        $tokens = array();
        foreach ($values as $key => $data) {
            $values[$key] = $data['v'];
            $tokens[$key] = \md5(\serialize($data['v']));
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $ttl = $this->ttl($expire);
        // APC doesn't support replace; I'll use get to check key existence,
        // then safely replace with cas
        $current = $this->get($key, $token);
        if ($current === false) {
            return false;
        }
        // negative TTLs don't always seem to properly treat the key as deleted
        if ($ttl < 0) {
            $this->delete($key);

            return true;
        }
        // no need for locking - cas will do that
        return $this->cas($token, $key, $value, $ttl);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $expire = $this->expiry($expire);
        $ttl = $this->ttl($expire);
        // negative TTLs don't always seem to properly treat the key as deleted
        if ($ttl < 0) {
            $this->delete($key);

            return true;
        }
        // lock required for CAS
        if (!$this->lock($key)) {
            return false;
        }
        $success = $this->apcStore($key, array(
            'v' => $value,
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : null,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null,
        ), $ttl);
        $this->stashTtl($key, $ttl);
        $this->unlock($key);
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        if (empty($items)) {
            return array();
        }
        $expire = $this->expiry($expire);
        $ttl = $this->ttl($expire);
        // negative TTLs don't always seem to properly treat the key as deleted
        if ($ttl < 0) {
            $this->deleteMultiple(\array_keys($items));
            return \array_fill_keys(\array_keys($items), true);
        }
        // attempt to get locks for all items
        $locked = $this->lock(\array_keys($items));
        $locked = \array_fill_keys($locked, null);
        $failed = \array_diff_key($items, $locked);
        $items = \array_intersect_key($items, $locked);
        foreach ($items as $key => $value) {
            $items[$key] = array(
                'v' => $value,
                'e' => $expire,
                // 'eo' => $expire,
                'ct' => null,
            );
        }
        if ($items) {
            // only write to those where lock was acquired
            $this->apcStore($items, null, $ttl);
            $this->stashTtl(\array_keys($items), $ttl);
            $this->unlock(\array_keys($items));
        }
        $return = array();
        foreach (\array_keys($items) as $key) {
            $return[$key] = !\array_key_exists($key, $failed);
        }
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        $ttl = $this->ttl($expire);
        // shortcut - expiring is similar to deleting, but the former has no
        // 1-operation equivalent
        if ($ttl < 0) {
            return $this->delete($key);
        }
        // get existing TTL & quit early if it's that one already
        $iterator = $this->APCuIterator('/^'.\preg_quote($key, '/').'$/', \APC_ITER_VALUE | \APC_ITER_TTL, 1, \APC_LIST_ACTIVE);
        $current = $iterator->current();
        if (!$current) {
            // doesn't exist
            return false;
        }
        if ($current['ttl'] === $ttl) {
            // that's the TTL already, no need to reset it
            return true;
        }
        // generate CAS token to safely CAS existing value with new TTL
        $value = $current['value'];
        $token = \serialize($value);
        return $this->cas($token, $key, $value, $ttl);
    }

    /*
        Protected/internal
    */

    /**
     * @param string|string[] $key key name or key/value array
     * @param mixed           $val value to store
     * @param integer         $ttl TTL in seconds
     *
     * @return boolean|boolean[]
     */
    protected function apcAdd($key, $val, $ttl = 0)
    {
        if (\function_exists('apcu_add')) {
            return \apcu_add($key, $val, $ttl);
        } else {
            return \apc_add($key, $val, $ttl);
        }
    }

    /**
     * @return boolean
     */
    protected function apcClearCache()
    {
        if (\function_exists('apcu_clear_cache')) {
            return \apcu_clear_cache();
        } else {
            return \apc_clear_cache('user');
        }
    }

    /**
     * @param string|string[]|APCIterator|APCuIterator $key
     *
     * @return boolean|string[]
     */
    protected function apcDelete($key)
    {
        if (\function_exists('apcu_delete')) {
            return \apcu_delete($key);
        } else {
            return \apc_delete($key);
        }
    }

    /**
     * @param string|string[] $key
     * @param boolean         $success
     *
     * @return mixed|false
     */
    protected function apcFetch($key, &$success = null)
    {
        /*
            $key can also be numeric, in which case APC is able to retrieve it,
            but will have an invalid $key in the results array, and trying to
            locate it by its $key in that array will fail with `undefined index`.
            Work around this by requesting those values 1 by 1.
        */
        if (\is_array($key)) {
            $nums = \array_filter($key, 'is_numeric');
            if ($nums) {
                $values = array();
                foreach ($nums as $k) {
                    $values[$k] = $this->apcFetch((string) $k, $success);
                }
                $remaining = \array_diff($key, $nums);
                if ($remaining) {
                    $values += $this->apcFetch($remaining, $success2);
                    $success &= $success2;
                }

                return $values;
            }
        }
        if (\function_exists('apcu_fetch')) {
            return \apcu_fetch($key, $success);
        } else {
            return \apc_fetch($key, $success);
        }
    }

    /**
     * @param string|string[] $key key
     * @param mixed           $var value
     * @param integer         $ttl time to live
     *
     * @return boolean|bool[]
     */
    protected function apcStore($key, $var, $ttl = 0)
    {
        /*
            $key can also be a [$key => $value] array, where key is numeric,
            but got cast to int by PHP. APC doesn't seem to store such numerical
            key, so we'll have to take care of those one by one.
        */
        if (\is_array($key)) {
            $nums = \array_filter(\array_keys($key), 'is_numeric');
            if ($nums) {
                $success = array();
                $nums = \array_intersect_key($key, \array_fill_keys($nums, null));
                foreach ($nums as $k => $v) {
                    $success[$k] = $this->apcStore((string) $k, $v, $ttl);
                }
                $remaining = \array_diff_key($key, $nums);
                if ($remaining) {
                    $success += $this->apcStore($remaining, $var, $ttl);
                }
                return $success;
            }
        }
        if (\function_exists('apcu_store')) {
            return \apcu_store($key, $var, $ttl);
        } else {
            return \apc_store($key, $var, $ttl);
        }
    }

    /**
     * @param string|string[]|null $search
     * @param integer              $format
     * @param integer              $chunk_size
     * @param integer              $list
     *
     * @return APCIterator|APCuIterator
     */
    protected function APCuIterator($search = null, $format = null, $chunk_size = null, $list = null)
    {
        $arguments = \func_get_args();
        if (\class_exists('APCuIterator', false)) {
            // I can't set the defaults parameter values because the APC_ or
            // APCU_ constants may not exist, so I'll just initialize from
            // func_get_args, not passing those params that haven't been set
            $reflect = new \ReflectionClass('APCuIterator');
            return $reflect->newInstanceArgs($arguments);
        } else {
            \array_unshift($arguments, 'user');
            $reflect = new \ReflectionClass('APCIterator');
            return $reflect->newInstanceArgs($arguments);
        }
    }

    /**
     * Shared between increment/decrement: both have mostly the same logic
     * (decrement just increments a negative value), but need their validation
     * split up (increment won't accept negative values).
     *
     * @param string  $key     key
     * @param integer $offset  amount to add
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param integer $expire
     *
     * @return integer|boolean
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        $ttl = $this->ttl($expire);
        /*
            APC has apc_inc & apc_dec, which work great. However, they don't allow for a TTL to be set.
            We'll just do a get, implement the increase or decrease in PHP, then CAS the new value
            (1 operation + CAS).
        */
        $value = $this->get($key, $token);
        if ($value === false) {
            // don't even set initial value, it's already expired...
            if ($ttl < 0) {
                return $initial;
            }
            $success = $this->cas($token, $key, $initial, $ttl);
            return $success ? $initial : false;
        }
        if (!\is_numeric($value)) {
            return false;
        }
        $value += $offset;
        // negative TTLs don't always seem to properly treat the key as deleted
        if ($ttl < 0) {
            $success = $this->delete($key);
            return $success ? $value : false;
        }
        $success = $this->cas($token, $key, $value, $ttl);
        return $success ? $value : false;
    }

    /**
     * Acquire a lock. If we failed to acquire a lock, it'll automatically try
     * again in 1ms, for a maximum of 10 times.
     *
     * APC provides nothing that would allow us to do CAS. To "emulate" CAS,
     * we'll work with locks: all cache writes also briefly create a lock
     * cache entry (yup: #writes * 3, for lock & unlock - luckily, they're
     * not over the network)
     * Writes are disallows when a lock can't be obtained (= locked by
     * another write), which makes it possible for us to first retrieve,
     * compare & then set in a nob-atomic way.
     * However, there's a possibility for interference with direct APC
     * access touching the same keys - e.g. other scripts, not using this
     * class. If CAS is of importance, make sure the only things touching
     * APC on your server is using these classes!
     *
     * @param string|string[] $keys
     *
     * @return array Array of successfully locked keys
     */
    protected function lock($keys)
    {
        // both string (single key) and array (multiple) are accepted
        $keys = (array) $keys;
        $locked = array();
        for ($i = 0; $i < 10; ++$i) {
            $locked += $this->lockAcquire($keys);
            $keys = \array_diff($keys, $locked);

            if (empty($keys)) {
                break;
            }
            \usleep(1);
        }
        return $locked;
    }

    /**
     * Acquire a lock - required to provide CAS functionality.
     *
     * @param string|string[] $keys keys to lock
     *
     * @return string[] Array of successfully locked keys
     */
    protected function lockAcquire($keys)
    {
        $strlenLockPrefix = \strlen($this->lockPrefix);
        $keys = (array) $keys;
        $values = array();
        foreach ($keys as $key) {
            $values[$this->lockPrefix.$key] = null;
        }
        // there's no point in locking longer than max allowed execution time
        // for this script
        $ttl = \ini_get('max_execution_time');
        // lock these keys, then compile a list of successfully locked keys
        // (using the returned failure array)
        $result = (array) $this->apcAdd($values, null, $ttl);
        $failed = array();
        foreach ($result as $key => $err) {
            $failed[] = \substr($key, $strlenLockPrefix);
        }
        return \array_diff($keys, $failed);
    }

    /**
     * Store the expiration time for items we're setting in this request, to
     * work around APC's behavior of only clearing expires per page request.
     *
     * @param array|string $key cache key(s)
     * @param integer      $ttl time-to-live
     *
     * @return void

     * @see static::$expires
     */
    protected function stashTtl($key = array(), $ttl = 0)
    {
        if ($ttl === 0) {
            // there's no point in storing expiry when there's no expiry
            return;
        }
        // $key can be both string (1 key) or array (multiple)
        $keys = (array) $key;
        $time = \time() + $ttl;
        foreach ($keys as $key) {
            $this->expires[$key] = $time;
        }
    }

    /**
     * Add a bit of "TTL buffer" to the ttl we pass to APC
     * We want to have access to the value after it expires
     *   there could be a failure when recalculating...
     *   in this case, we'd like to continue using the expired value
     *
     * @param integer $ttl time to live
     *
     * @return integer
     */
    protected function ttlExtend($ttl)
    {
        if ($ttl > 0) {
            $ttl = $ttl + \min(60*60, \max(60, $ttl * 0.25));
        }
        return (int) $ttl;
    }

    /**
     * Release a lock.
     *
     * @param string|string[] $keys
     *
     * @return boolean
     */
    protected function unlock($keys)
    {
        $keys = (array) $keys;
        foreach ($keys as $i => $key) {
            $keys[$i] = $this->lockPrefix.$key;
        }
        $this->apcDelete($keys);
        return true;
    }
}
