<?php

namespace bdk\SimpleCache\Tests\Psr16;

use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\SimpleCache;
use bdk\SimpleCache\Tests\AdapterTestCase;

class Psr16TestCase extends AdapterTestCase
{
    /**
     * @var SimpleCache
     */
    protected $simplecache;

    public function setAdapter(KeyValueStoreInterface $adapter)
    {
        $this->cache = $adapter;
        $this->simplecache = new SimpleCache($adapter);
    }
}
