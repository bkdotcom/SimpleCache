<?php

namespace bdk\SimpleCache;

/**
 * Interface for key-value storage engines.
 */
interface KeyValueStoreInterface
{

    /**
     * Add an item
     *
     * This operation fails (returns false) if the key already exists in cache.
     * If the operation succeeds, true will be returned.
     *
     * @param string $key    key to add
     * @param mixed  $value  value
     * @param mixed  $expire null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return boolean
     */
    public function add($key, $value, $expire = 0);

    /**
     * Replaces an item in 1 atomic operation, to ensure it didn't change since
     * it was originally read, when the CAS token was issued.
     *
     * This operation fails (returns false) if the CAS token doesn't match with
     * what's currently in store, or if the key doesn't exist
     *
     * @param mixed  $token  Token received from get() or getMultiple()
     * @param string $key    key
     * @param mixed  $value  value
     * @param mixed  $expire null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return boolean
     */
    public function cas($token, $key, $value, $expire = 0);

    /**
     * Clears the entire cache (or the everything for the given collection).
     *
     * @return boolean
     */
    public function clear();

    /**
     * Decrements a counter value, or sets an initial value if it does not yet
     * exist.
     *
     * The new counter value will be returned if this operation succeeds, or
     * false for failure (e.g. when the value currently in cache is not a
     * number, in which case it can't be decremented)
     *
     * @param string  $key     key
     * @param integer $offset  Value to decrement with
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param mixed   $expire  null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return integer|boolean New value or false on failure
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0);

    /**
     * Deletes an item from the cache.
     * Returns true if item existed & was successfully deleted, false otherwise.
     *
     * Return value is a boolean true when the operation succeeds, or false on
     * failure.
     *
     * @param string $key key to delete
     *
     * @return boolean
     */
    public function delete($key);

    /**
     * Deletes multiple items at once (reduced network traffic compared to
     * individual operations).
     *
     * Return value will be an associative array in [key => status] form, where
     * status is a boolean true for success, or false for failure.
     *
     * @param string[] $keys keys to delete
     *
     * @return boolean[]
     */
    public function deleteMultiple(array $keys);

    /**
     * Retrieves an item
     *
     * Optionally, an 2nd variable can be passed to this function. It will be
     * filled with a value that can be used for cas()
     *
     * @param string $key   key to get
     * @param mixed  $token Will be filled with the CAS token
     *
     * @return mixed value (or false on miss)
     */
    public function get($key, &$token = null);

    /**
     * Returns an isolated subset (collection) in which to store or fetch data
     * from.
     *
     * A new KeyValueStore object will be returned, one that will only have
     * access to this particular subset of data. Exact implementation can vary
     * between adapters (e.g. separate database, prefixed keys, ...), but it
     * will only ever provide access to data within this collection.
     *
     * It is not possible to set/fetch data across collections.
     * Setting the same key in 2 different collections will store 2 different
     * values, that can only be retrieved from their respective collections.
     * Clearing a collection will only clear those specific keys and will leave
     * keys in other collections untouched.
     * Clearing the server, however, will wipe out everything, including data in
     * any of the collections on that server.
     *
     * @param string $name Collection name
     *
     * @return KeyValueStoreInterface A new KeyValueStore instance representing only a
     *                       subset of data on this server
     */
    public function getCollection($name);

    /**
     * Get information regarding last get
     *
     * @return array
     *     lastModified     when was it last modified/written
     *     expiry           when does it expire
     *     expiryOriginal   when did the value first expire
     *     expiredValue     the expired value
     *     wasMiss          get returns false on miss... but false could have been a legit value
     */
    public function getInfo();

    /**
     * Retrieves multiple items at once.
     *
     * Return value will be an associative array in [key => value] format.
     * Keys missing in store will be omitted from the array.
     *
     * Optionally, an 2nd variable can be passed to this function. It will be
     * filled with values that can be used for cas(), in an associative array in
     * [key => token] format. Keys missing in cache will be omitted from the
     * array.
     *
     * getMultiple is preferred over multiple individual get operations as you'll
     * get them all in 1 request.
     *
     * @param array   $keys   keys to get
     * @param mixed[] $tokens Will be filled with the CAS tokens, in [key => token] format
     *
     * @return mixed[] [key => value]
     */
    public function getMultiple(array $keys, array &$tokens = null);

    /**
     * Retrieves an item
     * if item is a miss (non-existent or expired), $getter will be called to get the value,
     *    which will be stored with $expire expiry
     *
     * There are conditions where value may be expired, yet getter will not be called
     * In expired value will be returned
     * * stampeed protection:  another process is refreshing the cache
     * * getter recently failed and failExtend time has not ellapsed
     *
     * @param string   $key        key to set
     * @param callable $getter     callable called if key is a miss
     * @param integer  $expire     Time when item falls out of the cache:
     *                       0 = permanent (doesn't expire);
     *                       under 2592000 (30 days) = relative time, in seconds from now;
     *                       over 2592000 = absolute time, unix timestamp
     * @param integer  $failExtend If getter fails, by how much do we extend expiration (seconds) ?
     *
     * @return mixed
     */
    public function getSet($key, callable $getter, $expire = 0, $failExtend = 60);

    /**
     * Increments a counter value, or sets an initial value if it does not yet exist.
     *
     * The new counter value will be returned if this operation succeeds, or
     * false for failure (e.g. when the value currently in cache is not a
     * number, in which case it can't be incremented)
     *
     * @param string  $key     key
     * @param integer $offset  Value to increment with
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param mixed   $expire  null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return integer|boolean New value or false on failure
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0);

    /**
     * Replaces an item.
     *
     * This operation fails (returns false) if the key does not yet exist in
     * cache. If the operation succeeds, true will be returned.
     *
     * @param string  $key    key
     * @param mixed   $value  value
     * @param integer $expire Time when item falls out of the cache:
     *                       0 = permanent (doesn't expire);
     *                       under 2592000 (30 days) = relative time, in seconds from now;
     *                       over 2592000 = absolute time, unix timestamp
     *
     * @return boolean
     */
    // public function replace($key, $value, $expire = 0);

    /**
     * Stores a value, regardless of whether or not the key already exists (in
     * which case it will overwrite the existing value for that key).
     *
     * @param string $key    key to set
     * @param mixed  $value  value
     * @param mixed  $expire null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return boolean
     */
    public function set($key, $value, $expire = 0);

    /**
     * Store multiple values at once.
     *
     * Return value will be an associative array in [key => status] form, where
     * status is a boolean true for success, or false for failure.
     *
     * setMultiple is preferred over multiple individual set operations as you'll
     * set them all in 1 request.
     *
     * @param mixed[] $items  [key => value]
     * @param mixed   $expire null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return bool[]
     */
    public function setMultiple(array $items, $expire = 0);

    /**
     * Updates an item's expiration time without altering the stored value.
     *
     * @param string  $key    key
     * @param integer $expire Time when item falls out of the cache:
     *                       0 = permanent (doesn't expire);
     *                       under 2592000 (30 days) = relative time, in seconds from now;
     *                       over 2592000 = absolute time, unix timestamp
     *
     * @return boolean
     */
    public function touch($key, $expire);
}
