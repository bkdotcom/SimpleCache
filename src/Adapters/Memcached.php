<?php

namespace bdk\SimpleCache\Adapters;

use bdk\SimpleCache\Adapters\Collections\Utils\PrefixReset as Collection;
use bdk\SimpleCache\Exception\InvalidKey;
use bdk\SimpleCache\Exception\OperationFailed;

/**
 * Memcached adapter. Basically just a wrapper over \Memcached, but in an
 * exchangeable (KeyValueStore) interface.
 */
class Memcached extends Base
{
    /**
     * @var Memcached
     */
    protected $client;

    /**
     * Constructor
     *
     * @param \Memcached $client Memcached instance
     */
    public function __construct(\Memcached $client)
    {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $key = $this->encodeKey($key);
        $expire = $this->expiry($expire);
        $value = array(
            'v' => $value,
            'e' => $expire,
            // 'eo' => $expire,
            'ct' => null,
        );
        return $this->client->add($key, $value, $this->expireExtend($expire));
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        if (!\is_float($token) && !\is_int($token)) {
            return $this->add($key, $value, $expire);
        }
        $expire = $this->expiry($expire);
        $success = $this->client->cas($token, $this->encodeKey($key), array(
            'v' => $value,
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : $expire,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null
        ), $this->expireExtend($this->expiry($expire)));
        if (!$success && $this->client->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $this->add($key, $value, $expire);
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->client->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $key = $this->encodeKey($key);
        return $this->client->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        if (empty($keys)) {
            return array();
        }
        if (!\method_exists($this->client, 'deleteMulti')) {
            /*
                HHVM didn't always support deleteMulti, so we'll hack around it by
                setting all items expired.
                We could also delete() all items one by one, but that would
                probably take more network requests (this version always takes 2)

                @see http://docs.hhvm.com/manual/en/memcached.deletemulti.php
            */
            $values = $this->getMultiple($keys);
            $keys = \array_map(array($this, 'encodeKey'), \array_keys($values));
            $this->client->setMulti(\array_fill_keys($keys, ''), \time() - 1);
            $return = array();
            foreach ($keys as $key) {
                $key = $this->decodeKey($key);
                $return[$key] = \array_key_exists($key, $values);
            }
            return $return;
        }
        $keys = \array_map(array($this, 'encodeKey'), $keys);
        $result = (array) $this->client->deleteMulti($keys);
        $keys = \array_map(array($this, 'decodeKey'), \array_keys($result));
        $result = \array_combine($keys, $result);
        /*
            Contrary to docs (http://php.net/manual/en/memcached.deletemulti.php)
            deleteMulti returns an array of [key => true] (for successfully
            deleted values) and [key => error code] (for failures)
        */
        foreach ($result as $key => $status) {
            $result[$key] = $status === true;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        /*
            Using getMulti() instead of get() because the latter is flawed in earlier versions
            @see https://github.com/php-memcached-dev/php-memcached/issues/21
        */
        $values = $this->getMultiple(array($key), $tokens);
        if (!isset($values[$key])) {
            $token = null;
            return false;
        }
        $token = $tokens[$key];
        return $values[$key];
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
        $populateLastGetInfo = \count($keys) == 1;
        if ($populateLastGetInfo) {
            $this->resetLastGetInfo($keys[0]);
        }
        $keys = \array_map(array($this, 'encodeKey'), $keys);
        if (defined('\Memcached::GET_EXTENDED')) {
            // Memcached v3
            $return = $this->client->getMulti($keys, \Memcached::GET_EXTENDED);
            $this->throwExceptionOnClientCallFailure($return);
            foreach ($return as $key => $value) {
                $tokens[$key] = $value['cas'];
                $return[$key] = $value['value'];
            }
        } else {
            $return = $this->client->getMulti($keys, $tokens);
            $this->throwExceptionOnClientCallFailure($return);
        }
        $return = $return ?: array();
        $tokens = $tokens ?: array();
        $keys = \array_map(array($this, 'decodeKey'), \array_keys($return));
        $return = \array_combine($keys, $return);
        $tokens = \array_combine($keys, $tokens);
        foreach ($return as $key => $data) {
            $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
            $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
            if ($populateLastGetInfo) {
                $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
                    'calcTime' => $data['ct'],
                    'code' => 'hit',
                    'expiry' => $data['e'],
                    // 'expiryOriginal' => $data['eo'],
                    'token' => $tokens[$key],
                ));
                if ($isExpired) {
                    $this->lastGetInfo['code'] = 'expired';
                    $this->lastGetInfo['expiredValue'] = $data['v'];
                    $return[$key] = false;
                }

            }
            if ($isExpired) {
                // we don't return misses
                unset($return[$key]);
                unset($tokens[$key]);
                continue;
            }
            $return[$key] = $data['v'];
        }
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $key = $this->encodeKey($key);
        return $this->client->replace($key, $value, $expire);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        // Memcached seems to not timely purge items the way it should when
        // storing it with an expired timestamp
        $expire = $this->expiry($expire);
        if ($expire !== 0 && $expire < \time()) {
            $this->delete($key);
            return true;
        }
        $key = $this->encodeKey($key);
        $value = array(
            'v' => $value,
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : null,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null,
        );
        return $this->client->set($key, $value, $this->expireExtend($expire));
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
        if ($expire !== 0 && $expire < \time()) {
            // setting expired?
            // delete instead
            $keys = \array_keys($items);
            $this->deleteMultiple($keys);
            return \array_fill_keys($keys, true);
        }
        /*
            Numerical strings turn into integers when used as array keys, and
            HHVM (used to) reject(s) such cache keys.
            @see https://github.com/facebook/hhvm/pull/7654
        */
        $items = \array_map(function ($val) use ($expire) {
            return array(
                'v' => $val,
                'e' => $expire,
                // 'eo' => $expire,
                'ct' => null,
            );
        }, $items);
        $expire = $this->expireExtend($expire);
        if (\defined('HHVM_VERSION')) {
            $nums = \array_filter(\array_keys($items), 'is_numeric');
            if ($nums) {
                $success = array();
                $nums = \array_intersect_key($items, \array_fill_keys($nums, null));
                foreach ($nums as $k => $v) {
                    $success[$k] = $this->set((string) $k, $v, $expire);
                }
                $remaining = \array_diff_key($items, $nums);
                if ($remaining) {
                    $success += $this->setMultiple($remaining, $expire);
                }
                return $success;
            }
        }
        $keys = \array_map(array($this, 'encodeKey'), \array_keys($items));
        $items = \array_combine($keys, $items);
        $success = $this->client->setMulti($items, $expire);
        $keys = \array_map(array($this, 'decodeKey'), \array_keys($items));
        return \array_fill_keys($keys, $success);
    }

    /**
     * {@inheritdoc}
     *
     * HHVM doesn't support touch.
     *
     * PHP does, but only with \Memcached::OPT_BINARY_PROTOCOL == true,
     * and even then, it appears to be buggy on particular versions of Memcached.
     *
     * @see http://docs.hhvm.com/manual/en/memcached.touch.php
     */
    public function touch($key, $expire)
    {
        if ($expire !== 0 && $expire < \time()) {
            return $this->delete($key);
        }
        $value = $this->get($key, $token);
        return $this->cas($token, $key, $value, $expire);
    }

    /*
        Protected/internal
    */

    /**
     * {@inheritdoc}
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        /*
            Not using \Memcached::increment because it:
            * needs \Memcached::OPT_BINARY_PROTOCOL == true
            * is prone to errors after a flush ("merges" with pruned data) in at
              least some particular versions of Memcached
        */
        $value = $this->get($key, $token);
        if ($value === false) {
            $success = $this->add($key, $initial, $expire);
            return $success ? $initial : false;
        }
        if (!\is_numeric($value)) {
            return false;
        }
        $value += $offset;
        $success = $this->cas($token, $key, $value, $expire);
        return $success ? $value : false;
    }

    /**
     * Encode a key for use on the wire inside the memcached protocol.
     *
     * We encode spaces and line breaks to avoid protocol errors.
     * We encode the other control characters for compatibility with libmemcached's verify_key.
     * We leave other punctuation alone, to maximise backwards compatibility.
     *
     * @param string $key key to encode
     *
     * @return string
     *
     * @throws InvalidKey On invalid memcached key.
     * @see    https://github.com/wikimedia/mediawiki/commit/be76d869#diff-75b7c03970b5e43de95ff95f5faa6ef1R100
     * @see    https://github.com/wikimedia/mediawiki/blob/master/includes/libs/objectcache/MemcachedBagOStuff.php#L116
     */
    protected function encodeKey($key)
    {
        $regex = '/[^\x21\x22\x24\x26-\x39\x3b-\x7e]+/';
        $key = \preg_replace_callback($regex, function ($match) {
            return \rawurlencode($match[0]);
        }, $key);
        if (\strlen($key) > 255) {
            throw new InvalidKey(
                "Invalid key: $key. Encoded Memcached keys can not exceed 255 chars."
            );
        }
        return $key;
    }

    /**
     * Add a bit of "expiration buffer" to the expiry we pass to memcached
     * We want to have access to the value after it expires
     *   there could be a failure when recalculating...
     *   in this case, we'd like to continue using the expired value
     *
     * @param integer $expire expire timestamp
     *
     * @return integer
     */
    protected function expireExtend($expire)
    {
        $tsNow = \time();
        if ($expire > $tsNow) {
            $tsDiff = $expire - $tsNow;
            $expire = $expire + \min(60*60, \max(60, $tsDiff * 0.25));
        }
        return $expire;
    }

    /**
     * Decode a key encoded with encode().
     *
     * @param string $key ke to decode
     *
     * @return string
     */
    protected function decodeKey($key)
    {
        // matches %20, %7F, ... but not %21, %22, ...
        // (=the encoded versions for those encoded in encode)
        $regex = '/%(?!2[1246789]|3[0-9]|3[B-F]|[4-6][0-9A-F]|5[0-9A-E])[0-9A-Z]{2}/i';
        return \preg_replace_callback($regex, function ($match) {
            return \rawurldecode($match[0]);
        }, $key);
    }

    /**
     * Will throw an exception if the returned result from a Memcached call
     * indicates a failure in the operation.
     * The exception will contain debug information about the failure.
     *
     * @param mixed $result result from client getMulti
     *
     * @throws OperationFailed
     */
    protected function throwExceptionOnClientCallFailure($result)
    {
        if ($result !== false) {
            return;
        }
        throw new OperationFailed(
            $this->client->getResultMessage(),
            $this->client->getResultCode()
        );
    }
}
