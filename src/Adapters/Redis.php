<?php

namespace bdk\SimpleCache\Adapters;

use bdk\SimpleCache\Adapters\Collections\Redis as Collection;
use bdk\SimpleCache\KeyValueStoreInterface;

/**
 * Redis adapter. Basically just a wrapper over \Redis, but in an exchangeable
 * (KeyValueStore) interface.
 */
class Redis extends Base
{
    /**
     * @var \Redis
     */
    protected $client;

    /**
     * @var string|null
     */
    protected $version;

    /**
     * @param \Redis $client
     */
    public function __construct(\Redis $client)
    {
        $this->client = $client;

        // set a serializer if none is set already
        if ($this->client->getOption(\Redis::OPT_SERIALIZER) == \Redis::SERIALIZER_NONE) {
            $this->client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $expiry = $this->expiry($expire);
        $ttl = $this->ttl($expiry);
        if ($ttl < 0) {
            // don't add expired already value
            // check if exists
            $this->get($key);
            return $this->lastGetInfo['code'] != 'hit';
        }
        $value = array(
            'v' => $value,
            'e' => $expiry,
            'ct' => null,
        );
        if ($ttl === 0) {
            return $this->client->setnx($key, $value);
        }
        $this->client->multi();
        $this->client->setnx($key, $value);
        $this->client->expire($key, $ttl);
        /** @var bool[] $return */
        $return = (array) $this->client->exec();
        return !\in_array(false, $return);
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $this->client->watch($key);
        $this->get($key);
        if ($token != $this->lastGetInfo['token']) {
            /*
                HHVM Redis only got unwatch recently
                @see https://github.com/asgrim/hhvm/commit/bf5a259cece5df8a7617133c85043608d1ad5316
            */
            if (\method_exists($this->client, 'unwatch')) {
                $this->client->unwatch();
            } else {
                // this should also kill the watch...
                $this->client->multi()->discard();
            }

            return false;
        }
        $expiry = $this->expiry($expire);
        $ttl = $this->ttl($expiry);
        // since we're watching the key, this will fail if another process has changed the value
        $this->client->multi();
        if ($ttl < 0) {
            // just delete it
            $this->client->del($key);
        } else {
            $this->client->set($key, array(
                'v' => $value,
                'e' => $expiry,
                'ct' => null,
            ), $this->ttlExtend($ttl));
        }
        /** @var bool[] $return */
        $return = (array) $this->client->exec();
        return !\in_array(false, $return);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->client->flushAll();
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        return (bool) $this->client->del($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        if (empty($keys)) {
            return array();
        }
        /*
            del will only return the amount of deleted entries, but we also want
            to know which failed. Deletes will only fail for items that don't
            exist, so we'll just ask for those and see which are missing.
        */
        $items = $this->getMultiple($keys);
        $this->client->del($keys);
        $return = array();
        foreach ($keys as $key) {
            $return[$key] = \array_key_exists($key, $items);
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
        $this->client->multi();
        $this->client->get($key);
        $this->client->exists($key);
        /** @var array $return */
        $return = $this->client->exec();
        if ($return === false) {
            return false;
        }
        $data = $return[0];
        $exists = $return[1];
        if (!$exists) {
            return false;
        }
        $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
        $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
        $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
            'calcTime' => $data['ct'],
            'code' => 'hit',
            'expiry' => $data['e'],
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
        if (!\is_numeric($name)) {
            throw new InvalidCollection(
                'Redis database names must be numeric. '.\serialize($name).' given.'
            );
        }
        // we can't reuse $this->client in a different object, because it'll
        // operate on a different database
        $client = new \Redis();
        if ($this->client->getPersistentID() !== null) {
            $client->pconnect(
                $this->client->getHost(),
                $this->client->getPort(),
                $this->client->getTimeout()
            );
        } else {
            $client->connect(
                $this->client->getHost(),
                $this->client->getPort(),
                $this->client->getTimeout()
            );
        }
        $auth = $this->client->getAuth();
        if ($auth !== null) {
            $client->auth($auth);
        }
        $readTimeout = $this->client->getReadTimeout();
        if ($readTimeout) {
            $client->setOption(\Redis::OPT_READ_TIMEOUT, $this->client->getReadTimeout());
        }
        return new Collection($client, $name);
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
        $this->client->multi();
        $this->client->mget($keys);
        foreach ($keys as $key) {
            $this->client->exists($key);
        }
        /** @var array $return */
        $return = $this->client->exec();
        if ($return === false) {
            return array();
        }
        $values = \array_shift($return);
        $exists = $return;
        if ($values === false) {
            $values = \array_fill_keys($keys, false);
        }
        $values = \array_combine($keys, $values);
        $exists = \array_combine($keys, $exists);
        $tokens = array();
        $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
        foreach ($values as $key => $data) {
            if ($exists[$key] === false) {
                // remove non-existing value
                unset($values[$key]);
                continue;
            }
            $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
            if ($isExpired) {
                unset($values[$key]);
                continue;
            }
            $values[$key] = $data['v'];
            $tokens[$key] = \md5(\serialize($data['v']));
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $ttl = $this->ttl($expire);
        // Negative ttl behavior isn't properly documented & doesn't always
        // appear to treat the value as non-existing. Let's play safe and just
        // delete it right away!
        if ($ttl < 0) {
            return $this->delete($key);
        }
        // Redis supports passing set() an extended options array since >=2.6.12
        // which allows for an easy and 1-request way to replace a value.
        // That version already comes with Ubuntu 14.04. Ubuntu 12.04 (still
        // widely used and in LTS) comes with an older version, however, so I
        // want to support that too.
        // Supporting both versions comes at a cost.
        // I'll optimize for recent versions, which will get (in case of replace
        // failure) 1 additional network request (for version info). Older
        // versions will get 2 additional network requests: a failed replace
        // (because the options are unknown) & a version check.
        if ($this->version === null || $this->supportsOptionsArray()) {
            $options = array('xx');
            if ($ttl > 0) {
                // Not adding 0 TTL to options:
                // * HHVM (used to) interpret(s) wrongly & throw an exception
                // * it's not needed anyway, for 0...
                // @see https://github.com/facebook/hhvm/pull/4833
                $options['ex'] = $ttl;
            }
            // either we support options array or we haven't yet checked, in
            // which case I'll assume a recent server is running
            $result = $this->client->set($key, $value, $options);
            if ($result !== false) {
                return $result;
            }
            if ($this->supportsOptionsArray()) {
                // failed execution, but not because our Redis version is too old
                return false;
            }
        }
        // workaround for old Redis versions
        $this->client->watch($key);
        $exists = $this->client->exists('key');
        if (!$exists) {
            // HHVM Redis only got unwatch recently
            // @see https://github.com/asgrim/hhvm/commit/bf5a259cece5df8a7617133c85043608d1ad5316
            if (\method_exists($this->client, 'unwatch')) {
                $this->client->unwatch();
            } else {
                // this should also kill the watch...
                $this->client->multi()->discard();
            }
            return false;
        }
        // since we're watching the key, this will fail should it change in the
        // meantime
        $this->client->multi();
        if ($ttl) {
            $this->client->set($key, $value, $ttl);
        } else {
            $this->client->set($key, $value);
        }
        // @var bool[] $return
        $return = (array) $this->client->exec();
        return !\in_array(false, $return);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $expiry = $this->expiry($expire);
        $ttl = $this->ttl($expiry);
        if ($ttl < 0) {
            // setting an expired value??
            // just delete it now and be done with it
            $this->delete($key);
            return true;
        }
        /*
            @see https://github.com/phpredis/phpredis#set
            @see http://redis.io/commands/SET
        */
        $value = array(
            'v' => $value,
            'e' => $expiry,
            'ct' => null,
        );
        $ret =  $this->client->set($key, $value, $this->ttlExtend($ttl));
        return $ret;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        if (empty($items)) {
            return array();
        }
        $expiry = $this->expiry($expire);
        $ttl = $this->ttl($expiry);
        if ($ttl < 0) {
            // just delete em now and be done with it
            $this->deleteMultiple(\array_keys($items));
            return \array_fill_keys(\array_keys($items), true);
        }
        foreach ($items as $key => $value) {
            $items[$key] = array(
                'v' => $value,
                'e' => $expiry,
                'ct' => null,
            );
        }
        if ($ttl === 0) {
            $success = $this->client->mset($items);
            return \array_fill_keys(\array_keys($items), $success);
        }
        $ttlExtended = $this->ttlExtend($ttl);
        $this->client->multi();
        $this->client->mset($items);
        // Redis has no convenient multi-expire method
        foreach (\array_keys($items) as $key) {
            $this->client->expire($key, $ttlExtended);
        }
        /* @var bool[] $return */
        $result = (array) $this->client->exec();
        $return = array();
        $keys = \array_keys($items);
        $success = \array_shift($result);
        foreach ($result as $i => $value) {
            $key = $keys[$i];
            $return[$key] = $success && $value;
        }
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        $ttl = $this->ttl($expire);
        if ($ttl < 0) {
            // Redis can't set expired, so just remove in that case ;)
            return (bool) $this->client->del($key);
        }
        return $this->client->expire($key, $ttl);
    }

    /*
        Protected/internal
    */

    /**
     * {@inheritdoc}
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        $expiry = $this->expiry($expire);
        $ttl = $this->ttl($expiry);
        $this->client->watch($key);
        $value = $this->client->get($key);
        if ($value === false) {
            if ($ttl < 0) {
                return $initial;
            }
            // value is not yet set, store initial value!
            $this->client->multi();
            $this->client->set($key, array(
                'v' => $initial,
                'e' => $expiry,
                'ct' => null,
            ), $this->ttlExtend($ttl));
            $return = (array) $this->client->exec();
            return !\in_array(false, $return) ? $initial : false;
        }
        $value = $value['v'];
        // can't increment if a non-numeric value is set
        if (!\is_numeric($value)) {
            /*
                HHVM Redis only got unwatch recently.
                @see https://github.com/asgrim/hhvm/commit/bf5a259cece5df8a7617133c85043608d1ad5316
            */
            if (\method_exists($this->client, 'unwatch')) {
                $this->client->unwatch();
            } else {
                // this should also kill the watch...
                $this->client->multi()->discard();
            }
            return false;
        }
        $value += $offset;
        $this->client->multi();
        if ($ttl < 0) {
            $this->client->del($key);
        } else {
            $this->client->set($key, array(
                'v' => $value,
                'e' => $expiry,
                'ct' => null,
            ), $this->ttlExtend($ttl));
        }
        $return = (array) $this->client->exec();
        return !\in_array(false, $return) ? $value : false;
    }

    /**
     * Returns the version of the Redis server we're connecting to.
     *
     * @return string
     */
    protected function getVersion()
    {
        if ($this->version === null) {
            $info = $this->client->info();
            $this->version = $info['redis_version'];
        }
        return $this->version;
    }

    /**
     * Check if passing an options array to set() is supported.
     *
     * @return boolean
     */
    protected function supportsOptionsArray()
    {
        return \version_compare($this->getVersion(), '2.6.12') >= 0;
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
        if ($ttl === 0) {
            return null;
        }
        if ($ttl > 0) {
            $ttl = $ttl + \min(60*60, \max(60, $ttl * 0.25));
        }
        return (int) $ttl;
    }
}
