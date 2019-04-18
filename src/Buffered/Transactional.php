<?php

namespace bdk\SimpleCache\Buffered;

use bdk\SimpleCache\Buffered\Utils\Buffer;
use bdk\SimpleCache\Buffered\Utils\Transaction;
use bdk\SimpleCache\Exception\UnbegunTransaction;
use bdk\SimpleCache\KeyValueStoreInterface;

/**
 * In addition to buffering cache data in memory (see Buffered), this class
 * will add transactional capabilities.
 *
 * Writes can be deferred by starting a transaction & will only go out when you commit them.
 * This makes it possible to defer cache updates until we can guarantee it's
 * safe (e.g. until we successfully committed everything to persistent storage).
 *
 * There will be some trickery to make sure that, after we've made changes to
 * cache (but not yet committed), we don't read from the real cache anymore, but
 * instead serve the in-memory equivalent that we'll be writing to real cache
 * when all goes well.
 *
 * If a commit fails, all keys affected will be deleted to ensure no corrupt
 * data stays behind.
 */
class Transactional implements KeyValueStoreInterface
{
    /**
     * Array of KeyValueStore objects. Every cache action will be executed
     * on the last item in this array, so transactions can be nested.
     *
     * @var KeyValueStoreInterface[]
     */
    protected $transactions = array();

    /**
     * @param KeyValueStoreInterface $kvs The real cache we'll buffer for
     */
    public function __construct(KeyValueStoreInterface $kvs)
    {
        $this->transactions[] = $kvs;
    }

    /**
     * Roll back uncommitted transactions.
     */
    public function __destruct()
    {
        while (\count($this->transactions) > 1) {
            /** @var Transaction $transaction */
            $transaction = \array_pop($this->transactions);
            $transaction->rollback();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->add($key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->cas($token, $key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $kvs = \end($this->transactions);
        return $kvs->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->decrement($key, $offset, $initial, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $kvs = \end($this->transactions);
        return $kvs->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        $kvs = \end($this->transactions);
        return $kvs->deleteMultiple($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $kvs = \end($this->transactions);
        return $kvs->get($key, $token);
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        $kvs = \end($this->transactions);
        return new static($kvs->getCollection($name));
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo()
    {
        $kvs = \end($this->transactions);
        return $kvs->getInfo();
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $kvs = \end($this->transactions);
        return $kvs->getMultiple($keys, $tokens);
    }

    /**
     * {@inheritdoc}
     */
    public function getSet($key, callable $getter, $expire = 0, $failExtend = 60)
    {
        $kvs = \end($this->transactions);
        return $kvs->getSet($key, $getter, $expire, $failExtend);
    }

    /**
     * {@inheritdoc}
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->increment($key, $offset, $initial, $expire);
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->replace($key, $value, $expire);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->set($key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        $kvs = \end($this->transactions);
        return $kvs->setMultiple($items, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        $kvs = \end($this->transactions);
        return $kvs->touch($key, $expire);
    }

    /**
     * Initiate a transaction: this will defer all writes to real cache until
     * commit() is called.
     */
    public function begin()
    {
        // we'll rely on buffer to respond data that has not yet committed, so
        // it must never evict from cache - I'd even rather see the app crash
        $buffer = new Buffer(\ini_get('memory_limit'));
        // transactions can be nested: the previous transaction will serve as
        // cache backend for the new cache (so when committing a nested
        // transaction, it will commit to the parent transaction)
        $kvs = \end($this->transactions);
        $this->transactions[] = new Transaction($buffer, $kvs);
    }

    /**
     * Commits all deferred updates to real cache.
     * If the any write fails, all subsequent writes will be aborted & all keys
     * that had already been written to will be deleted.
     *
     * @return boolean
     *
     * @throws UnbegunTransaction
     */
    public function commit()
    {
        if (\count($this->transactions) <= 1) {
            throw new UnbegunTransaction('Attempted to commit without having begun a transaction.');
        }
        $transaction = \array_pop($this->transactions);
        return $transaction->commit();
    }

    /**
     * Roll back all scheduled changes.
     *
     * @return boolean
     *
     * @throws UnbegunTransaction
     */
    public function rollback()
    {
        if (\count($this->transactions) <= 1) {
            throw new UnbegunTransaction('Attempted to rollback without having begun a transaction.');
        }
        /** @var Transaction $transaction */
        $transaction = \array_pop($this->transactions);
        return $transaction->rollback();
    }
}
