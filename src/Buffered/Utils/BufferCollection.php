<?php

namespace MatthiasMullie\Scrapbook\Buffered\Utils;

use bdk\SimpleCache\Adapters\Collections\Memory as MemoryCollection;

/**
 * A collection implementation for Buffer.
 */
class BufferCollection extends MemoryCollection
{
    /**
     * @var Buffer
     */
    protected $cache;

    /**
     * @param Buffer $cache Buffer Instance
     * @param string $name  Name of buffer
     */
    public function __construct(Buffer $cache, $name)
    {
        parent::__construct($cache, $name);
    }

    /**
     * Check if a key existed in local storage, but is now expired.
     *
     * Because our local buffer is also just a real cache, expired items will
     * just return nothing, which will lead us to believe no such item exists in
     * that local cache, and we'll reach out to the real cache (where the value
     * may not yet have been expired because that may have been part of an
     * uncommitted write)
     * So we'll want to know when a value is in local cache, but expired!
     *
     * @param string $key key to check
     *
     * @return boolean
     */
    public function expired($key)
    {
        if ($this->get($key) !== false) {
            // returned a value, clearly not yet expired
            return false;
        }

        // a known item, not returned by get, is expired
        return \array_key_exists($key, $this->cache->items);
    }
}
