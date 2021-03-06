<?php

namespace bdk\SimpleCache\Buffered\Utils;

use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Adapters\Collections\Memory as BufferCollection;

/**
 * This is a helper class for Buffered & Transactional wrappers, which buffer
 * real cache requests in memory.
 *
 * This class accepts 2 caches:
 *   a Buffer instance (to read data from as long as it hasn't been committed)
 *   and a KeyValueStore object (the real cache)
 *
 * Every write action will first store the data in the Buffer instance, and
 * then pass update along to $defer.
 * Once commit() is called, $defer will execute all these updates against the
 * real cache. All deferred writes that fail to apply will cause that cache key
 * to be deleted, to ensure cache consistency.
 * Until commit() is called, all data is read from the temporary Buffer instance.
 */
class Transaction implements KeyValueStoreInterface
{
    /**
     * @var KeyValueStoreInterface
     */
    protected $kvs;

    /**
     * @var Buffer
     */
    protected $buffer;

    /**
     * We'll return stub CAS tokens in order to reliably replay the CAS actions
     * to the real `. This will hold a map of stub token => value, used to
     * verify when we do the actual CAS.
     *
     * @var mixed[]
     * @see cas()
     */
    protected $tokens = array();

    /**
     * Deferred updates to be committed to real cache.
     *
     * @var Defer
     */
    protected $defer;

    /**
     * Suspend reads from real cache. This is used when a flush is issued but it
     * has not yet been committed. In that case, we don't want to fall back to
     * real cache values, because they're about to be flushed.
     *
     * @var boolean
     */
    protected $suspend = false;

    /**
     * @var Transaction[]
     */
    protected $collections = array();

    /**
     * Keep track of keys we're deleting
     *
     * @var array
     */
    protected $deletedKeys = array();

    /**
     * Constructor
     *
     * @param Buffer|BufferCollection $buffer Buffer Instance
     * @param KeyValueStoreInterface  $kvs    Real KeyValueStore
     */
    public function __construct(/* Buffer|BufferCollection */ $buffer, KeyValueStoreInterface $kvs)
    {
        // can't do double typehint, so let's manually check the type
        if (!$buffer instanceof Buffer && !$buffer instanceof BufferCollection) {
            $error = 'Invalid class for $buffer: '.\get_class($buffer);
            if (\class_exists('\TypeError')) {
                throw new \TypeError($error);
            }
            \trigger_error($error, E_USER_ERROR);
        }
        $this->kvs = $kvs;
        // (uncommitted) writes must never be evicted (even if that means
        // crashing because we run out of memory)
        $this->buffer = $buffer;
        $this->defer = new Defer($this->kvs);
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        // before adding, make sure the value doesn't yet exist (in real cache,
        // nor in memory)
        if ($this->get($key) !== false) {
            return false;
        }
        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        $this->defer->add($key, $value, $expire);
        return true;
    }

    /**
     * Since our CAS is deferred, the CAS token we got from our original
     * get() will likely not be valid by the time we want to store it to
     * the real cache. Imagine this scenario:
     * * a value is fetched from (real) cache
     * * an new value key is CAS'ed (into temp cache - real CAS is deferred)
     * * this key's value is fetched again (this time from temp cache)
     * * and a new value is CAS'ed again (into temp cache...).
     *
     * In this scenario, when we finally want to replay the write actions
     * onto the real cache, the first 3 actions would likely work fine.
     * The last (second CAS) however would not, since it never got a real
     * updated $token from the real cache.
     *
     * To work around this problem, all get() calls will return a unique
     * CAS token and store the value-at-that-time associated with that
     * token. All we have to do when we want to write the data to real cache
     * is, right before we CAS for real, get the value & (real) cas token
     * from storage & compare that value to the one we had stored. If that
     * checks out, we can safely resume the CAS with the real token we just
     * received.
     *
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $originalValueHash = isset($this->tokens[$token])
            ? $this->tokens[$token]
            : null;
        $valueCur = $this->get($key);
        $tokenValCur = \md5(\serialize($valueCur));
        if ($tokenValCur !== $originalValueHash) {
            // value is no longer the same as what we used for token
            return false;
        }
        // "CAS" value to local cache/memory
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        // only schedule the CAS to be performed on real cache if it was OK on local cache
        $this->defer->cas($originalValueHash, $key, $value, $expire);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        foreach ($this->collections as $collection) {
            $collection->clear();
        }
        $success = $this->buffer->clear();
        if ($success === false) {
            return false;
        }
        // clear all buffered writes, flush wipes them out anyway
        $this->clearTransactionData();
        // make sure that reads, from now on until commit, don't read from cache
        $this->suspend = true;
        $this->defer->clear();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        if ($offset <= 0 || $initial < 0) {
            return false;
        }
        // get existing value (from real cache or memory) so we know what to
        // increment in memory (where we may not have anything yet, so we should
        // adjust our initial value to what's already in real cache)
        $value = $this->get($key);
        if ($value === false) {
            $value = $initial + $offset;
        }
        if (!\is_numeric($value) || !\is_numeric($offset)) {
            return false;
        }
        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $value = $value - $offset;
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        $this->defer->decrement($key, $offset, $initial, $expire);
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        // check the current value to see if it currently exists, so we can
        // properly return true/false as would be expected from KeyValueStore
        $value = $this->get($key);
        if ($value === false) {
            return false;
        }
        /*
            To make sure that subsequent get() calls for this key don't return
            a value (it's supposed to be deleted), we'll make it expired in
            our buffer cache.
        */
        $this->deletedKeys[] = $key;
        $this->buffer->set($key, $value, -1);
        $this->defer->delete($key);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        // check the current values to see if they currently exists, so we can
        // properly return true/false as would be expected from KeyValueStore
        $items = $this->getMultiple($keys);
        $success = array();
        foreach ($keys as $key) {
            $success[$key] = \array_key_exists($key, $items);
        }
        // only attempt to store those that we've deleted successfully to local
        $values = \array_intersect_key($success, \array_flip($keys));
        if (empty($values)) {
            return array();
        }
        // mark all as expired in local cache (see comment in delete())
        $this->buffer->setMultiple($values, -1);
        $this->defer->deleteMultiple(\array_keys($values));
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $value = $this->buffer->get($key, $token);
        // short-circuit reading from real cache if we have an uncommitted flush
        if ($this->suspend && $token === null) {
            // flush hasn't been committed yet, don't read from real cache!
            return false;
        }
        if ($value === false) {
            if ($this->buffer->expired($key)) {
                /*
                    Item used to exist in local cache, but is now expired. This
                    is used when values are to be deleted: we don't want to reach
                    out to real storage because that would respond with the not-
                    yet-deleted value.
                */
                return false;
            }
            // unknown in local cache = fetch from source cache
            $value = $this->kvs->get($key);
        }
        // no value = quit early, don't generate a useless token
        if ($value === false) {
            return false;
        }
        /*
            $token will be unreliable to the deferred updates so generate
            a custom one and keep the associated value around.
            Read more details in PHPDoc for function cas().
            uniqid is ok here. Doesn't really have to be unique across
            servers, just has to be unique every time it's called in this
            one particular request - which it is.
        */
        $token = \uniqid();
        $this->tokens[$token] = \md5(\serialize($value));
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        if (!isset($this->collections[$name])) {
            $this->collections[$name] = new static(
                $this->buffer->getCollection($name),
                $this->kvs->getCollection($name)
            );
        }
        return $this->collections[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo()
    {
        $info = $this->buffer->getInfo();
        if (\in_array($info['key'], $this->deletedKeys)) {
            $info = \array_merge($info, array(
                'code' => 'notExist',
                'expiredValue' => null,
                'expiry' => null,
                'token' => null,
            ));
        }
        return $info;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        // retrieve all that we can from local cache
        $values = $this->buffer->getMultiple($keys);
        $tokens = array();
        // short-circuit reading from real cache if we have an uncommitted flush
        if (!$this->suspend) {
            // figure out which missing key we need to get from real cache
            $keys = \array_diff($keys, \array_keys($values));
            foreach ($keys as $i => $key) {
                // don't reach out to real cache for keys that are about to be gone
                if ($this->buffer->expired($key)) {
                    unset($keys[$i]);
                }
            }
            // fetch missing values from real cache
            if ($keys) {
                $missing = $this->kvs->getMultiple($keys);
                $values += $missing;
            }
        }
        // any tokens we get will be unreliable, so generate some replacements
        // (more elaborate explanation in get())
        foreach ($values as $key => $value) {
            $token = \uniqid();
            $tokens[$key] = $token;
            $this->tokens[$token] = \md5(\serialize($value));
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function getSet($key, callable $getter, $expire = 0, $failExtend = 60)
    {
        $value = $this->buffer->getSet($key, $getter, $expire, $failExtend);
        // @todo.. only call set if necessary
        $this->defer->set($key, $value, $expire);
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        if ($offset <= 0 || $initial < 0) {
            return false;
        }
        // get existing value (from real cache or memory) so we know what to
        // increment in memory (where we may not have anything yet, so we should
        // adjust our initial value to what's already in real cache)
        $value = $this->get($key);
        if ($value === false) {
            $value = $initial - $offset;
        }
        if (!\is_numeric($value) || !\is_numeric($offset)) {
            return false;
        }
        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $value = $value + $offset;
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        $this->defer->increment($key, $offset, $initial, $expire);
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        // before replacing, make sure the value actually exists (in real cache,
        // or already created in memory)
        if ($this->get($key) === false) {
            return false;
        }
        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        $this->defer->replace($key, $value, $expire);
        return true;
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        // store the value in memory, so that when we ask for it again later in
        // this same request, we get the value we just set
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        $this->defer->set($key, $value, $expire);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        // store the values in memory, so that when we ask for it again later in
        // this same request, we get the value we just set
        $success = $this->buffer->setMultiple($items, $expire);
        // only attempt to store those that we've set successfully to local
        $successful = \array_intersect_key($items, $success);
        if (!empty($successful)) {
            $this->defer->setMultiple($successful, $expire);
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        // grab existing value (from real cache or memory) and re-save (to
        // memory) with updated expiration time
        $value = $this->get($key);
        if ($value === false) {
            return false;
        }
        $success = $this->buffer->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }
        $this->defer->touch($key, $expire);
        return true;
    }

    /**
     * Commits all deferred updates to real cache.
     * that had already been written to will be deleted.
     *
     * @return boolean
     */
    public function commit()
    {
        $this->clearTransactionData();
        return $this->defer->commit();
    }

    /**
     * Roll back all scheduled changes.
     *
     * @return boolean
     */
    public function rollback()
    {
        $this->clearTransactionData();
        $this->defer->clearWrites();
        return true;
    }

    /**
     * Clears all transaction-related data stored in memory.
     */
    protected function clearTransactionData()
    {
        $this->tokens = array();
        $this->suspend = false;
    }
}
