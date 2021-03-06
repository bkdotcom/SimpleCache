<?php

namespace bdk\SimpleCache\Buffered\Utils;

use bdk\SimpleCache\Exception\UncommittedTransaction;
use bdk\SimpleCache\KeyValueStoreInterface;

/**
 * This is a helper class for transactions. It will optimize the write going
 * out and take care of rolling back.
 *
 * Optimizations will be:
 * * multiple set() values (with the same expiration) will be applied in a
 *   single setMultiple()
 * * for a set() followed by another set() on the same key, only the latter
 *   one will be applied
 * * same for an replace() followed by an increment(), or whatever operation
 *   happens on the same key: if we can pre-calculate the end result, we'll
 *   only execute 1 operation with the end result
 * * operations before a flush() will not be executed, they'll just be lost
 *
 * Rollback strategy includes:
 * * fetching the original value of operations prone to fail (add, replace &
 *   cas) prior to executing them
 * * executing said operations before the others, to minimize changes of
 *   interfering concurrent writes
 * * if the commit fails, said original values will be restored in case the
 *   new value had already been stored
 *
 * This class must never receive invalid data. E.g. a "replace" can never
 * follow a "delete" of the same key. This should be guaranteed by whatever
 * uses this class: there is no point in re-implementing these checks here.
 * The only acceptable conflicts are when cache values have changed outside,
 * from another process. Those will be handled by this class.
 */
class Defer
{
    /**
     * Cache to write to.
     *
     * @var KeyValueStore
     */
    protected $kvs;

    /**
     * All updates will be scheduled by key. If there are multiple updates
     * for a key, they can just be folded together.
     * E.g. 2 sets, the later will override the former.
     * E.g. set + increment, might as well set incremented value immediately.
     *
     * This is going to be an array that holds horrible arrays of update data,
     * being:
     * * 0: the operation name (set, add, ...) so we're able to sort them
     * * 1: a callable, to apply the update to cache
     * * 2: the array of data to supply to the callable
     *
     * @var array[]
     */
    protected $keys = array();

    /**
     * Flush is special - it's not specific to (a) key(s), so we can't store
     * it to $keys.
     *
     * @var boolean
     */
    protected $flush = false;

    /**
     * Constructor
     *
     * @param KeyValueStoreInterface $kvs KeyValueStore instance
     */
    public function __construct(KeyValueStoreInterface $kvs)
    {
        $this->kvs = $kvs;
    }

    /**
     * @throws UncommittedTransaction
     */
    public function __destruct()
    {
        if (!empty($this->keys)) {
            throw new UncommittedTransaction(
                'Transaction is about to be destroyed without having been '.
                'committed or rolled back.'
            );
        }
    }

    /**
     * @param string  $key    key to add
     * @param mixed   $value  new value
     * @param integer $expire expiry
     *
     * @return void
     */
    public function add($key, $value, $expire)
    {
        $args = array(
            'key' => $key,
            'value' => $value,
            'expire' => $expire,
        );
        $this->keys[$key] = array(__FUNCTION__, array($this->kvs, __FUNCTION__), $args);
    }

    /**
     * @param mixed   $originalValueHash No real CAS token, but the original value for this key
     * @param string  $key               key
     * @param mixed   $value             new value
     * @param integer $expire            expiry
     *
     * @return void
     */
    public function cas($originalValueHash, $key, $value, $expire)
    {
        /*
            If we made it here, we're sure that logically, the CAS applies with
            respect to other operations in this transaction. That means we don't
            have to verify the token here: whatever has already been set/add/
            replace/cas will have taken care of that and we already know this one
            applies on top op that change. We can just fold it in there & update
            the value we set initially.
        */
        if (isset($this->keys[$key]) && \in_array($this->keys[$key][0], array('set', 'add', 'replace', 'cas'))) {
            $this->keys[$key][2]['value'] = $value;
            $this->keys[$key][2]['expire'] = $expire;
            return;
        }
        /*
            @param mixed $token
            @param string $key
            @param mixed $value
            @param int $expire
            @return bool
        */
        $kvs = $this->kvs;
        $callback = function ($originalValueHash, $key, $value, $expire) use ($kvs) {
            // check if given (local) CAS token was known
            if ($originalValueHash === null) {
                return false;
            }
            // fetch data from real kvs, getting new valid CAS token
            $valCurrent = $kvs->get($key, $tokenKvs);
            // check if the value we just read from real cache is still the same
            // as the one we saved when doing the original fetch
            $currentValueHash = \md5(\serialize($valCurrent));
            if ($currentValueHash === $originalValueHash) {
                // everything still checked out, CAS the value for real now
                return $kvs->cas($tokenKvs, $key, $value, $expire);
            }
            return false;
        };
        $args = array(
            'token' => $originalValueHash,
            'key' => $key,
            'value' => $value,
            'expire' => $expire,
        );
        $this->keys[$key] = array(__FUNCTION__, $callback, $args);
    }

    /**
     * Clear all scheduled updates, they'll be wiped out after this anyway
     *
     * @return void
     */
    public function clear()
    {
        $this->keys = array();
        $this->flush = true;
    }

    /**
     * Clears all scheduled writes.
     *
     * @return void
     */
    public function clearWrites()
    {
        $this->keys = array();
        $this->flush = false;
    }

    /**
     * Commit all deferred writes to cache.
     *
     * When the commit fails, no changes in this transaction will be applied
     * (and those that had already been applied will be undone). False will
     * be returned in that case.
     *
     * @return boolean
     */
    public function commit()
    {
        list($old, $new) = $this->generateRollback();
        $updates = $this->generateUpdates();
        $updates = $this->combineUpdates($updates);
        \usort($updates, array($this, 'sortUpdates'));
        foreach ($updates as $update) {
            // apply update to cache & receive a simple bool to indicate
            // success (true) or failure (false)
            $success = \call_user_func_array($update[1], $update[2]);
            if ($success === false) {
                $this->rollback($old, $new);
                return false;
            }
        }
        $this->clearWrites();
        return true;
    }

    /**
     * @param string  $key     key
     * @param integer $offset  Value to decrement with
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param integer $expire  expiry
     *
     * @return void
     */
    public function decrement($key, $offset, $initial, $expire)
    {
        $this->doIncrement(__FUNCTION__, $key, $offset, $initial, $expire);
    }

    /**
     * @param string $key key to delete
     *
     * @return void
     */
    public function delete($key)
    {
        $args = array('key' => $key);
        $this->keys[$key] = array(__FUNCTION__, array($this->kvs, __FUNCTION__), $args);
    }

    /**
     * @param string[] $keys keys to delete
     *
     * @return void
     */
    public function deleteMultiple(array $keys)
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
    }

    /**
     * @param string  $key     key
     * @param integer $offset  Value to increment with
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param integer $expire  expiry
     *
     * @return void
     */
    public function increment($key, $offset, $initial, $expire)
    {
        $this->doIncrement(__FUNCTION__, $key, $offset, $initial, $expire);
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $expire
     */
    /*
    public function replace($key, $value, $expire)
    {
        $args = array(
            'key' => $key,
            'value' => $value,
            'expire' => $expire,
        );
        $this->keys[$key] = array(__FUNCTION__, array($this->kvs, __FUNCTION__), $args);
    }
    */

    /**
     * @param string  $key    key
     * @param mixed   $value  new value
     * @param integer $expire expirty
     *
     * @return void
     */
    public function set($key, $value, $expire)
    {
        $args = array(
            'key' => $key,
            'value' => $value,
            'expire' => $expire,
        );
        $this->keys[$key] = array(__FUNCTION__, array($this->kvs, __FUNCTION__), $args);
    }

    /**
     * @param mixed[] $items  key/value array
     * @param integer $expire expiry
     *
     * @return void
     */
    public function setMultiple(array $items, $expire)
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value, $expire);
        }
    }

    /**
     * @param string  $key    key
     * @param integer $expire expiry
     *
     * @return void
     */
    public function touch($key, $expire)
    {
        if (isset($this->keys[$key]) && isset($this->keys[$key][2]['expire'])) {
            // changing expiration time of a value we're already storing in
            // this transaction - might as well just set new expiration time
            // right away
            $this->keys[$key][2]['expire'] = $expire;
        } else {
            $args = array(
                'key' => $key,
                'expire' => $expire,
            );
            $this->keys[$key] = array(__FUNCTION__, array($this->kvs, __FUNCTION__), $args);
        }
    }

    /**
     * We may have multiple sets & deletes, which can be combined into a single
     * setMultiple or deleteMultiple operation.
     *
     * @param array $updates [callable, $args][]
     *
     * @return array
     */
    protected function combineUpdates($updates)
    {
        $setMulti = array();
        $deleteMulti = array();

        foreach ($updates as $i => $update) {
            $operation = $update[0];
            $args = $update[2];

            switch ($operation) {
                // all set & delete operations can be grouped into setMulti & deleteMulti
                case 'set':
                    unset($updates[$i]);

                    // only group sets with same expiration
                    $setMulti[$args['expire']][$args['key']] = $args['value'];
                    break;
                case 'delete':
                    unset($updates[$i]);

                    $deleteMulti[] = $args['key'];
                    break;
                default:
                    break;
            }
        }

        if (!empty($setMulti)) {
            $kvs = $this->kvs;

            /*
             * We'll use the return value of all deferred writes to check if they
             * should be rolled back.
             * commit() expects a single bool, not a per-key array of success bools.
             *
             * @param mixed[] $items
             * @param int $expire
             * @return bool
             */
            $callback = function ($items, $expire) use ($kvs) {
                $success = $kvs->setMultiple($items, $expire);

                return !\in_array(false, $success);
            };

            foreach ($setMulti as $expire => $items) {
                $updates[] = array('setMulti', $callback, array($items, $expire));
            }
        }

        if (!empty($deleteMulti)) {
            $kvs = $this->kvs;

            /*
                commit() expected a single bool, not an array of success bools.
                Besides, deleteMultiple() is never cause for failure here: if the
                key didn't exist because it has been deleted elsewhere already,
                the data isn't corrupt, it's still as we'd expect it.

                @param string[] $keys
                @return bool
            */
            $callback = function ($keys) use ($kvs) {
                $kvs->deleteMultiple($keys);

                return true;
            };
            $updates[] = array('deleteMulti', $callback, array($deleteMulti));
        }
        return $updates;
    }

    /**
     * @param string  $operation "increment" or "decrement"
     * @param string  $key       key
     * @param integer $offset    Value to increment/decrement with
     * @param integer $initial   Initial value (if item doesn't yet exist)
     * @param integer $expire    expiry
     *
     * @return void
     */
    protected function doIncrement($operation, $key, $offset, $initial, $expire)
    {
        if (isset($this->keys[$key])) {
            if (\in_array($this->keys[$key][0], array('set', 'add', 'replace', 'cas'))) {
                // we're trying to increment a key that's only just being stored
                // in this transaction - might as well combine those
                $symbol = $this->keys[$key][1] === 'increment' ? 1 : -1;
                $this->keys[$key][2]['value'] += $symbol * $offset;
                $this->keys[$key][2]['expire'] = $expire;
            } elseif (\in_array($this->keys[$key][0], array('increment', 'decrement'))) {
                // we're trying to increment a key that's already being incremented
                // or decremented in this transaction - might as well combine those

                // we may be combining an increment with a decrement
                // we must carefully figure out how these 2 apply against each other
                $symbol = $this->keys[$key][0] === 'increment' ? 1 : -1;
                $previous = $symbol * $this->keys[$key][2]['offset'];

                $symbol = $operation === 'increment' ? 1 : -1;
                $current = $symbol * $offset;

                $offset = $previous + $current;

                $this->keys[$key][2]['offset'] = \abs($offset);
                // initial value must also be adjusted to include the new offset
                $this->keys[$key][2]['initial'] += $current;
                $this->keys[$key][2]['expire'] = $expire;

                // adjust operation - it might just have switched from increment to
                // decrement or vice versa
                $operation = $offset >= 0 ? 'increment' : 'decrement';
                $this->keys[$key][0] = $operation;
                $this->keys[$key][1] = array($this->kvs, $operation);
            } else {
                // touch & delete become useless if incrementing/decrementing after
                unset($this->keys[$key]);
            }
        }

        if (!isset($this->keys[$key])) {
            $args = array(
                'key' => $key,
                'offset' => $offset,
                'initial' => $initial,
                'expire' => $expire,
            );
            $this->keys[$key] = array($operation, array($this->kvs, $operation), $args);
        }
    }

    /**
     * Since we can't perform true atomic transactions, we'll fake it.
     * Most of the operations (set, touch, ...) can't fail. We'll do those last.
     * We'll first schedule the operations that can fail (cas, replace, add)
     * to minimize chances of another process overwriting those values in the
     * meantime.
     * But it could still happen, so we should fetch the current values for all
     * unsafe operations. If the transaction fails, we can then restore them.
     *
     * @return array[] Array of 2 [key => value] maps: current & scheduled data
     */
    protected function generateRollback()
    {
        $keys = array();
        $new = array();
        foreach ($this->keys as $key => $data) {
            $operation = $data[0];
            // we only need values for cas & replace - recovering from an 'add'
            // is just deleting the value...
            if (\in_array($operation, array('cas', 'replace', 'set'))) {
                $keys[] = $key;
                $new[$key] = $data[2]['value'];
            }
        }
        if (empty($keys)) {
            return array(array(), array());
        }
        // fetch the existing data & return the planned new data as well
        $current = $this->kvs->getMultiple($keys);
        return array($current, $new);
    }

    /**
     * By storing all updates by key, we've already made sure we don't perform
     * redundant operations on a per-key basis. Now we'll turn those into
     * actual updates.
     *
     * @return array
     */
    protected function generateUpdates()
    {
        $updates = array();

        if ($this->flush) {
            $updates[] = array('flush', array($this->kvs, 'clear'), array());
        }

        foreach ($this->keys as $data) {
            $updates[] = $data;
        }

        return $updates;
    }

    /**
     * Roll the cache back to pre-transaction state by comparing the current
     * cache values with what we planned to set them to.
     *
     * @param array $old key=>value array
     * @param array $new key=>value array
     *
     * @return void
     */
    protected function rollback(array $old, array $new)
    {
        foreach ($old as $key => $value) {
            $current = $this->kvs->get($key, $token);
            /*
                If the value right now equals the one we planned to write, it
                should be restored to what it was before. If it's yet something
                else, another process must've stored it and we should leave it
                alone.
            */
            if ($current === $new[$key]) {
                /*
                    CAS the rollback. If it fails, that means another process
                    has stored in the meantime and we can just leave it alone.
                */
                $info = $this->kvs->getInfo();
                $this->kvs->cas($token, $key, $value, $info['expiry']);
            }
        }
        $this->clearWrites();
    }

    /**
     * Change the order of the updates in this transaction to ensure we have those
     * most likely to fail first. That'll decrease odds of having to roll back, and
     * make rolling back easier.
     *
     * @param array $a Update, where index 0 is the operation name
     * @param array $b Update, where index 0 is the operation name
     *
     * @return integer
     */
    protected function sortUpdates(array $a, array $b)
    {
        $updateOrder = array(
            // there's no point in applying this after doing the below updates
            // we also shouldn't really worry about cas/replace failing after this,
            // there won't be any after cache having been flushed
            'clear',

            // prone to fail: they depend on certain conditions (token must match
            // or value must (not) exist)
            'cas',
            'replace',
            'add',

            // unlikely/impossible to fail, assuming the input is valid
            'touch',
            'increment',
            'decrement',
            'set', 'setMultiple',
            'delete', 'deleteMultiple',
        );
        if ($a[0] === $b[0]) {
            return 0;
        }
        return \array_search($a[0], $updateOrder) < \array_search($b[0], $updateOrder) ? -1 : 1;
    }
}
