<?php

namespace bdk\SimpleCache\Tests\Buffered;

use bdk\SimpleCache\Buffered\Buffered;
use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Tests\AdapterTest;

class BufferedAdapterTest extends AdapterTest
{
    public function setAdapter(KeyValueStoreInterface $kvs)
    {
        $this->cache = new Buffered($kvs);
    }
}
