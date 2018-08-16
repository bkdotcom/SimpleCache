<?php

namespace bdk\SimpleCache\Adapters\Collections;

use bdk\SimpleCache\Adapters\Memory as Adapter;
use bdk\SimpleCache\Adapters\Collections\Utils\PrefixKeys;
use ReflectionObject;

/**
 * MemoryStore adapter for a subset of data.
 */
class Memory extends PrefixKeys
{

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        /*
        $this->kvs->items is protected..
          - Doesn't make sense for $cache->items to be publicly accessable
          - This is very specific to MemoryStore implementation, it can assume
            these kind of implementation details (like how it's ok for a child
            to use protected methods - this just can't be a subclass for
            practical reasons, but it mostly acts like one)
          - Reflection is not the most optimized thing, but that doesn't matter
            too much for MemoryStore, which is not a *real* cache
        */
        $reflectionObj = new ReflectionObject($this->kvs);
        $reflectionProp = $reflectionObj->getProperty('items');
        $reflectionProp->setAccessible(true);
        $items = $reflectionProp->getValue($this->kvs);
        foreach (\array_keys($items) as $key) {
            if (\strpos($key, $this->prefix) === 0) {
                $this->kvs->delete($key);
            }
        }
        return true;
    }
}
