<?php

namespace bdk\SimpleCache\Tests\Scale;

use bdk\SimpleCache\Adapters\Memory;
use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Scale\Shard;
use bdk\SimpleCache\Tests\AdapterTest;

class ShardAdapterTest extends AdapterTest
{
    public function setAdapter(KeyValueStoreInterface $adapter)
    {
        $other = new Memory();
        $this->cache = new Shard($adapter, $other);
    }
}
