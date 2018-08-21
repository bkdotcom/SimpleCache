<?php

namespace bdk\SimpleCache\Adapters;

use bdk\SimpleCache\Adapters\Collections\Couchbase as Collection;

/**
 * Couchbase adapter. Basically just a wrapper over \CouchbaseBucket, but in an
 * exchangeable (KeyValueStore) interface.
 *
 * @see http://developer.couchbase.com/documentation/server/4.0/sdks/php-2.0/php-intro.html
 * @see http://docs.couchbase.com/sdk-api/couchbase-php-client-2.1.0/
 */
class Couchbase extends Base
{
    /**
     * @var \CouchbaseBucket
     */
    protected $client;

    /**
     * Constructor
     *
     * @param \CouchbaseBucket $client Couchbase Client/Bucket
     *
     * @throws Exception Unhealthy server.
     */
    public function __construct(\CouchbaseBucket $client)
    {
        $this->client = $client;
        /*
        $info = $this->client->manager()->info();
        foreach ($info['nodes'] as $node) {
            if ($node['status'] !== 'healthy') {
                throw new Exception('Server isn\'t ready yet');
            }
        }
        */
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        // $value = $this->serialize($value);
        $value = $this->encode($value, array(
            'e' => $expire,
            // 'eo' => $expire,
            'ct' => null,
        ));
        try {
            $result = $this->client->insert($key, $value, array('expiry' => $expire));
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $value = $this->encode($value, array(
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['expiryOriginal'],
            'ct' => (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000,
        ));
        try {
            $result = $token === null
                ? $this->client->upsert($key, $value, array('expiry' => $expire))
                : $this->client->replace($key, $value, array('expiry' => $expire, 'cas' => $token));
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        // depending on config & client version, flush may not be available
        try {
            /*
                Flush wasn't always properly implemented[1] in the client, plus
                it depends on server config[2] to be enabled. Return status has
                been null in both success & failure cases.
                Flush is a very pervasive function that's likely not called
                lightly. Since it's probably more important to know whether or
                not it succeeded, than having it execute as fast as possible, I'm
                going to add some calls and test if flush succeeded.
                1: https://forums.couchbase.com/t/php-flush-isnt-doing-anything/1886/8
                2: http://docs.couchbase.com/admin/admin/CLI/CBcli/cbcli-bucket-flush.html
            */
            $this->client->upsert('cb-flush-tester', '');
            $manager = $this->client->manager();
            if (\method_exists($manager, 'flush')) {
                // ext-couchbase >= 2.0.6
                $result = $manager->flush();
            } elseif (\method_exists($this->client, 'flush')) {
                // ext-couchbase < 2.0.6
                $this->client->flush();
            } else {
                return false;
            }
        } catch (\CouchbaseException $e) {
            return false;
        }
        try {
            // cleanup in case flush didn't go through; but if it did, we won't
            // be able to remove it and know flush succeeded
            $result = $this->client->remove('cb-flush-tester');
            return (bool) $result->error;
        } catch (\CouchbaseException $e) {
            // exception: "The key does not exist on the server"
            return true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        try {
            $result = $this->client->remove($key);
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        if (empty($keys)) {
            return array();
        }
        try {
            $results = $this->client->remove($keys);
        } catch (\CouchbaseException $e) {
            return \array_fill_keys($keys, false);
        }
        $success = array();
        foreach ($results as $key => $result) {
            $success[$key] = !$result->error;
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $token = null;
        $this->resetLastGetInfo($key);
        try {
            $result = $this->client->get($key);
        } catch (\CouchbaseException $e) {
            return false;
        }
        if ($result->error) {
            return false;
        }
        $data = $this->decode($result->value);
        $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
        $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
        $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
            'calcTime' => $data['ct'],
            'code' => 'hit',
            'expiry' => $data['e'],
            // 'expiryOriginal' => $data['eo'],
            'token' => $result->cas,
        ));
        if ($isExpired) {
            $this->lastGetInfo['code'] = 'expired';
            $this->lastGetInfo['expiredValue'] = $data['v'];
            return false;
        }
        $token = $result->cas;
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
        try {
            $results = $this->client->get($keys);
        } catch (\CouchbaseException $e) {
            return array();
        }
        $values = array();
        $tokens = array();
        foreach ($results as $key => $value) {
            if (!\in_array($key, $keys) || $value->error) {
                continue;
            }
            $data = $this->decode($value->value);
            $values[$key] = $data['v'];
            $tokens[$key] = $value->cas;
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        try {
            $result = $this->client->replace($key, $value, array('expiry' => $expire));
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $expire = $this->expiry($expire);
        if ($expire !== 0 && $expire < \time()) {
            // adding an expired value??
            // just delete it now and be done with it
            $this->delete($key);
            return true;
        }
        $value = $this->encode($value, array(
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : null,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null,
        ));
        try {
            $result = $this->client->upsert($key, $value, array('expiry' => $expire));
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
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
            // adding an expired values??
            // just delete them now and be done with it
            $keys = \array_keys($items);
            $this->deleteMultiple($keys);
            return \array_fill_keys($keys, true);
        }
        // attempting to insert integer keys (e.g. '0' as key is automatically
        // cast to int, if it's an array key) fails with a segfault, so we'll
        // have to do those piecemeal
        $integers = \array_filter(\array_keys($items), 'is_int');
        if ($integers) {
            $success = array();
            $integers = \array_intersect_key($items, \array_fill_keys($integers, null));
            foreach ($integers as $k => $v) {
                $success[$k] = $this->set((string) $k, $v, $expire);
            }
            $items = \array_diff_key($items, $integers);
            return \array_merge($success, $this->setMultiple($items, $expire));
        }
        foreach ($items as $key => $value) {
            $items[$key] = array(
                'value' => $this->encode($value, array(
                    'e' => $expire,
                    // 'eo' => $expire,
                    'ct' => null,
                )),
                'expiry' => $expire,
            );
        }
        try {
            $results = $this->client->upsert($items);
        } catch (\CouchbaseException $e) {
            return \array_fill_keys(\array_keys($items), false);
        }
        $success = array();
        foreach ($results as $key => $result) {
            $success[$key] = !$result->error;
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        if ($expire < 0 || ($expire > 2592000 && $expire < \time())) {
            return $this->delete($key);
        }
        try {
            $result = $this->client->getAndTouch($key, $expire);
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
    }

    /*
        Protected/internal
    */

    /**
     * Couchbase doesn't properly remember the data type being stored:
     * arrays and objects are turned into stdClass instances.
     *
     * @param mixed $value value to serialize
     *
     * @return string|mixed
     */
    /*
    protected function serialize($value)
    {
        return (\is_array($value) || \is_object($value))
            ? \serialize($value)
            : $value;
    }
    */

    /**
     * Restore serialized data.
     *
     * @param mixed $value serialized value
     *
     * @return mixed|integer|float
     */
    /*
    protected function unserialize($value)
    {
        $unserialized = @\unserialize($value);
        return $unserialized === false
            ? $value
            : $unserialized;
    }
    */
}
