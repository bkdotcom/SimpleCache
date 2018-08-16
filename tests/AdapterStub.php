<?php

namespace bdk\SimpleCache\Tests;

use Exception;
use bdk\SimpleCache\KeyValueStoreInterface;

/**
 * If an adapter fails to initialize, we'll want to proceed with the tests
 * anyway, just add tests for this particular adapter as fixed.
 */
class AdapterStub implements KeyValueStoreInterface
{
    /**
     * @var Exception
     */
    protected $exception;

    /**
     * @param Exception $exception
     */
    public function __construct(Exception $exception)
    {
        $this->exception = $exception;
    }

    public function add($key, $value, $expire = 0)
    {
        throw $this->exception;
    }

    public function cas($token, $key, $value, $expire = 0)
    {
        throw $this->exception;
    }

    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        throw $this->exception;
    }

    public function delete($key)
    {
        throw $this->exception;
    }

    public function deleteMultiple(array $keys)
    {
        throw $this->exception;
    }

    public function clear()
    {
        throw $this->exception;
    }

    public function get($key, &$token = null)
    {
        throw $this->exception;
    }

    public function getInfo()
    {
        throw $this->exception;
    }

    public function getSet($key, callable $getter, $expire = 0, $failDelay = 60)
    {
        throw $this->exception;
    }

    public function getCollection($name)
    {
        throw $this->exception;
    }

    public function getMultiple(array $keys, array &$tokens = null)
    {
        throw $this->exception;
    }

    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        throw $this->exception;
    }

    /*
    public function replace($key, $value, $expire = 0)
    {
        throw $this->exception;
    }
    */

    public function set($key, $value, $expire = 0)
    {
        throw $this->exception;
    }

    public function setMultiple(array $items, $expire = 0)
    {
        throw $this->exception;
    }

    /*
    public function touch($key, $expire)
    {
        throw $this->exception;
    }
    */
}
