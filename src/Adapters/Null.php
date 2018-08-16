<?php

namespace bdk\SimpleCache\Adapters;

/**
 * Null adapter. For when you don't want a cache at all!
 *
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @copyright Copyright (c) 2017, Brad Kent. All rights reserved
 * @license   LICENSE MIT
 */
class Null extends Base
{

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        $return = array_flip($keys);
        $return = array_map(function () {
            return false;
        }, $return);
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $this->resetLastGetInfo($key);
        $token = null;
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $values = array();
        $tokens = array();
        foreach ($keys as $key) {
            $values[$key] = false;
            $tokens[$key] = null;
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        return true;
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        return array_map(function () {
            return true;
        }, $items);
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function touch($key, $expire)
    {
        if ($expire < 0 || ($expire > 2592000 && $expire < time())) {
            return $this->delete($key);
        }
        try {
            $result = $this->client->getAndTouch($key, $expire);
        } catch (\CouchbaseException $e) {
            return false;
        }
        return !$result->error;
    }
    */

    /**
     * {@inheritdoc}
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        return $initial;
    }
}
