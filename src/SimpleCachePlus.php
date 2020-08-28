<?php

namespace bdk\SimpleCache;

use bdk\SimpleCache\SimpleCache;

/**
 * PSR-16 (SimpleCache) wrapper for KeyValueStoreInterface
 */
class SimpleCachePlus extends SimpleCache
{

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
        // \bdk\Debug::_getChannel('SimpleCache')->warn(__METHOD__, $key);
        $this->assertValidKey($key);
        $expiry = $this->ttlToExpiry($ttl);
        return $this->kvs->getSet($key, $getter, $expiry, $failExtend);
    }
}
