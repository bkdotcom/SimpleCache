<?php

namespace bdk\SimpleCache;

use DateInterval;
use DateTime;
use Traversable;
use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Psr16\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 (SimpleCache) wrapper for KeyValueStoreInterface
 */
class SimpleCache implements CacheInterface
{
    /**
     * List of invalid (or reserved) key characters.
     *
     * @var string
     */
    const KEY_INVALID_CHARACTERS = '{}()/\@:';

    /**
     * @var KeyValueStoreInterface
     */
    protected $kvs;

    /**
     * @param KeyValueStoreInterface $kvs KeyValueStoreInterface instance
     */
    public function __construct(KeyValueStoreInterface $kvs)
    {
        $this->kvs = $kvs;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $default = null)
    {
        $this->assertValidKey($key);
        $multi = $this->kvs->getMultiple(array($key));
        return isset($multi[$key]) ? $multi[$key] : $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl = null)
    {
        $this->assertValidKey($key);
        $ttl = $this->ttlToExpiry($ttl);
        return $this->kvs->set($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $this->assertValidKey($key);
        $this->kvs->delete($key);
        // as long as the item is gone from the cache (even if it never existed
        // and delete failed because of that), we should return `true`
        return true;
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
    public function getMultiple($keys, $default = null)
    {
        if ($keys instanceof Traversable) {
            $keys = \iterator_to_array($keys, false);
        }
        if (!\is_array($keys)) {
            throw new InvalidArgumentException(
                'Invalid keys: '.\var_export($keys, true).'. Keys should be an array or Traversable of strings.'
            );
        }
        \array_map(array($this, 'assertValidKey'), $keys);
        $results = $this->kvs->getMultiple($keys);
        // KeyValueStore omits values that are not in cache, while PSR-16 will
        // have them with a default value
        $nulls = \array_fill_keys($keys, $default);
        $results = \array_merge($nulls, $results);
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple($values, $ttl = null)
    {
        if ($values instanceof Traversable) {
            // we also need the keys, and an array is stricter about what it can
            // have as keys than a Traversable is, so we can't use iterator_to_array...
            $array = array();
            foreach ($values as $key => $value) {
                if (!\is_string($key) && !\is_int($key)) {
                    throw new InvalidArgumentException(
                        'Invalid values: '.\var_export($values, true).'. Only strings are allowed as keys.'
                    );
                }
                $array[$key] = $value;
            }
            $values = $array;
        }
        if (!\is_array($values)) {
            throw new InvalidArgumentException(
                'Invalid values: '.\var_export($values, true).'. Values should be an array or Traversable with strings as keys.'
            );
        }
        foreach ($values as $key => $value) {
            // $key is also allowed to be an integer, since ['0' => ...] will
            // automatically convert to [0 => ...]
            $key = \is_int($key) ? (string) $key : $key;
            $this->assertValidKey($key);
        }
        $ttl = $this->ttlToExpiry($ttl);
        $success = $this->kvs->setMultiple($values, $ttl);
        return !\in_array(false, $success);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys)
    {
        if ($keys instanceof Traversable) {
            $keys = \iterator_to_array($keys, false);
        }
        if (!\is_array($keys)) {
            throw new InvalidArgumentException(
                'Invalid keys: '.\var_export($keys, true).'. Keys should be an array or Traversable of strings.'
            );
        }
        \array_map(array($this, 'assertValidKey'), $keys);
        $this->kvs->deleteMultiple($keys);
        // as long as the item is gone from the cache (even if it never existed
        // and delete failed because of that), we should return `true`
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has($key)
    {
        $this->assertValidKey($key);
        // KeyValueStore::get returns false for cache misses (which could also
        // be confused for a `false` value), so we'll check existence with getMultiple
        $multi = $this->kvs->getMultiple(array($key));
        return isset($multi[$key]);
    }

    /*
        The following method(s) extend Psr\SimpleCache or are internal/private
    */

    /**
     * Retrieves an item
     * if item is a miss (non-existasnt or expired), $getter will be called to get the value,
     *    which will be stored with $expire expiry
     *
     * @param string                    $key        The unique key of this item in the cache
     * @param callable                  $getter     If cache miss, this function will be used to generate new value
     * @param null|integer|DateInterval $ttl        Optional. The TTL value of this item. If no value is sent and
     *                                               the driver supports TTL then the library may set a default value
     *                                               for it or let the driver take care of that.
     * @param integer                   $failExtend How long to before should retry getter after failure
     *
     * @return mixed The value of the item from the cache or getter function
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If the $key string is not a legal value.
     */
    public function getSet($key, callable $getter, $ttl = null, $failExtend = 60)
    {
        $this->assertValidKey($key);
        $expiry = $this->ttlToExpiry($ttl);
        return $this->kvs->getSet($key, $getter, $expiry, $failExtend);
    }

    /**
     * Throws an exception if $key is invalid.
     *
     * @param string $key key to validate
     *
     * @throws InvalidArgumentException Invalid key.
     */
    protected function assertValidKey($key)
    {
        if (!\is_string($key)) {
            throw new InvalidArgumentException(
                'Invalid key: '.\var_export($key, true).'. Key should be a string.'
            );
        }
        if ($key === '') {
            throw new InvalidArgumentException(
                'Invalid key. Key should not be empty.'
            );
        }
        // valid key according to PSR-16 rules
        $regexInvalid = '/['.\preg_quote(static::KEY_INVALID_CHARACTERS, '/').']/';
        if (\preg_match($regexInvalid, $key)) {
            throw new InvalidArgumentException(
                'Invalid key: '.$key.'. Contains (a) character(s) reserved '.
                'for future extension: '.static::KEY_INVALID_CHARACTERS
            );
        }
    }

    /**
     * Accepts all TTL inputs valid in PSR-16 (null|integer|DateInterval) and
     * converts them into expiry (absolute timestamp) for KeyValueStore (integer).
     *
     * @param null|integer|DateInterval $ttl Time-To-Live
     *
     * @return integer
     *
     * @throws InvalidArgumentException Non null|integer|DateInterval value.
     */
    protected function ttlToExpiry($ttl)
    {
        if ($ttl === null) {
            /*
                PSR-16 specifies null (default) is up to implementation
                we'll treat it as don't expire
            */
            return 0;
        } elseif (\is_int($ttl)) {
            /*
                PSR-16 specifies that if `0` is provided, it must be treated as expired,
            */
            if ($ttl === 0) {
                return -1;
            }
            return $ttl + \time();
        } elseif ($ttl instanceof DateInterval) {
            // convert DateInterval to integer by adding it to a 0 DateTime
            $datetime = new DateTime();
            $datetime->setTimestamp(0);
            $datetime->add($ttl);
            return (int) $datetime->format('U');
        }
        throw new InvalidArgumentException(
            'Invalid TTL: '.\serialize($ttl).'. Must be integer or instance of DateInterval.'
        );
    }
}
