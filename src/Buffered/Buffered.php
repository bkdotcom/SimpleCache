<?php

namespace bdk\SimpleCache\Buffered;

use bdk\SimpleCache\Buffered\Utils\Buffer;
use bdk\SimpleCache\Buffered\Utils\Transaction;
use bdk\SimpleCache\KeyValueStoreInterface;

/**
 * This class will serve as a local buffer to the real cache: anything read from
 * & written to the real cache will be stored in memory, so if any of those keys
 * is again requested in the same request, we can just grab it from memory
 * instead of having to get it over the wire.
 */
class Buffered implements KeyValueStoreInterface
{
    /**
     * Transaction will already buffer all writes (until the transaction
     * has been committed/rolled back). As long as we immediately commit
     * to real store, it'll look as if no transaction is in progress &
     * all we'll be left with is the local copy of all data that can act
     * as buffer for follow-up requests.
     * All we'll need to add is also buffering non-write results.
     *
     * @var Transaction
     */
    protected $transaction;

    /**
     * Local in-memory storage, for the data we've already requested from
     * or written to the real cache.
     *
     * @var Buffer
     */
    protected $local;

    /**
     * @var Buffered[]
     */
    protected $collections = array();

    /**
     * Constructor
     *
     * @param KeyValueStoreInterface $kvs The real cache we'll buffer for
     */
    public function __construct(KeyValueStoreInterface $kvs)
    {
        $this->local = new Buffer();
        $this->transaction = new Transaction($this->local, $kvs);
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $result = $this->transaction->add($key, $value, $expire);
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $result = $this->transaction->cas($token, $key, $value, $expire);
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        foreach ($this->collections as $collection) {
            $collection->clear();
        }
        $result = $this->transaction->clear();
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $result = $this->transaction->delete($key);
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        $result = $this->transaction->deleteMultiple($keys);
        $this->transaction->commit();

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        $result = $this->transaction->decrement($key, $offset, $initial, $expire);
        $this->transaction->commit();
        return $result;
    }

    /**
     * In addition to all writes being stored to $local, we'll also
     * keep get() values around ;).
     *
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $value = $this->transaction->get($key, $token);
        // only store if we managed to retrieve a value (valid token) and it's
        // not already in cache (or we may mess up tokens)
        if ($value !== false && $this->local->get($key, $localToken) === false && $localToken === null) {
            $this->local->set($key, $value);
        }
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        if (!isset($this->collections[$name])) {
            $collection = $this->transaction->getCollection($name);
            $this->collections[$name] = new static($collection);
        }
        return $this->collections[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo()
    {
        return $this->transaction->getInfo();
    }

    /**
     * In addition to all writes being stored to $local, we'll also
     * keep get() values around ;).
     *
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $values = $this->transaction->getMultiple($keys, $tokens);
        $missing = \array_diff_key($values, $this->local->getMultiple($keys));
        if (!empty($missing)) {
            $this->local->setMultiple($missing);
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function getSet($key, callable $getter, $expire = 0, $failExtend = 60)
    {
        $value = $this->transaction->getSet($key, $getter, $expire, $failExtend);
        // only store if we managed to retrieve a value (valid token) and it's
        // not already in cache (or we may mess up tokens)
        if ($value !== false && $this->local->get($key, $localToken) === false && $localToken === null) {
            $this->local->set($key, $value);
        }
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        $result = $this->transaction->increment($key, $offset, $initial, $expire);
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $result = $this->transaction->replace($key, $value, $expire);
        $this->transaction->commit();
        return $result;
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $result = $this->transaction->set($key, $value, $expire);
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        $result = $this->transaction->setMultiple($items, $expire);
        $this->transaction->commit();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        $result = $this->transaction->touch($key, $expire);
        $this->transaction->commit();
        return $result;
    }
}
