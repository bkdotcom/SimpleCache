<?php

namespace bdk\SimpleCache\Adapters\Collections\Utils;

use bdk\SimpleCache\KeyValueStoreInterface;

/**
 *
 */
class PrefixKeys implements KeyValueStoreInterface
{
    /**
     * @var KeyValueStore
     */
    protected $kvs;

    /**
     * @var string
     */
    protected $prefix;

    /**
     * Reflection property
     *
     * @var ReflectionProperty
     */
    protected $lastGetInfoProp;

    /**
     * Constructor
     *
     * @param KeyValueStoreInterface $kvs    adapter
     * @param string                 $prefix key prefix
     */
    public function __construct(KeyValueStoreInterface $kvs, $prefix)
    {
        $this->kvs = $kvs;
        $this->setPrefix($prefix);
        // store this so we can update KeyValueStoreInterface::$lastGetInfo (a protected prop) with min overhead
        $reflectionClass = new \ReflectionClass($this->kvs);
        $this->lastGetInfoProp = $reflectionClass->getProperty('lastGetInfo');
        $this->lastGetInfoProp->setAccessible(true);
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $key = $this->prefix($key);
        return $this->kvs->add($key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $key = $this->prefix($key);
        return $this->kvs->cas($token, $key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        $key = $this->prefix($key);
        return $this->kvs->decrement($key, $offset, $initial, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $key = $this->prefix($key);
        return $this->kvs->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        $keysPrefixed = \array_map(array($this, 'prefix'), $keys);
        $results = $this->kvs->deleteMultiple($keysPrefixed);
        $keys = \array_map(array($this, 'unfix'), \array_keys($results));
        return \array_combine($keys, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->kvs->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $return = $this->kvs->get($this->prefix($key), $token);
        /*
            KVS only knows of the prefixed key...
            we want kvs->getInfo() to return the unprefixed key
        */
        $info = $this->lastGetInfoProp->getValue($this->kvs);
        $info['key'] = $key;
        $this->lastGetInfoProp->setValue($this->kvs, $info);
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        return $this->kvs->getCollection($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo()
    {
        return $this->kvs->getInfo();
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $keysPrefixed = \array_map(array($this, 'prefix'), $keys);
        $results = $this->kvs->getMultiple($keysPrefixed, $tokens);
        $keys = \array_map(array($this, 'unfix'), \array_keys($results));
        $tokens = \array_combine($keys, $tokens);
        return \array_combine($keys, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function getSet($key, callable $getter, $expire = 0, $failDelay = 60)
    {
        $key = $this->prefix($key);
        return $this->kvs->getSet($key, $getter, $expire, $failDelay);
    }

    /**
     * {@inheritdoc}
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        $key = $this->prefix($key);
        return $this->kvs->increment($key, $offset, $initial, $expire);
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $key = $this->prefix($key);
        return $this->kvs->replace($key, $value, $expire);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $key = $this->prefix($key);
        return $this->kvs->set($key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        $keysPrefixed = \array_map(array($this, 'prefix'), \array_keys($items));
        $items = \array_combine($keysPrefixed, $items);
        $results = $this->kvs->setMultiple($items, $expire);
        $keys = \array_map(array($this, 'unfix'), \array_keys($results));
        return \array_combine($keys, $results);
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function touch($key, $expire)
    {
        $key = $this->prefix($key);
        return $this->kvs->touch($key, $expire);
    }
    */

    /**
     * add prefix to key
     *
     * @param string $key key to prefix
     *
     * @return string
     */
    protected function prefix($key)
    {
        return $this->prefix.$key;
    }

    /**
     * set prefix
     *
     * @param string $prefix prefix
     *
     * @return void
     */
    protected function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }

    /**
     * remove prefix
     *
     * @param string $key key to remove prefix from
     *
     * @return string
     */
    protected function unfix($key)
    {
        return \preg_replace('/^'.\preg_quote($this->prefix, '/').'/', '', $key);
    }
}
