<?php

namespace bdk\SimpleCache\Tests\Psr6;

use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Psr6\Pool;
use bdk\SimpleCache\Tests\AdapterTestCase;

class Psr6TestCase extends AdapterTestCase
{
    /**
     * @var Pool
     */
    protected $pool;

    public function setAdapter(KeyValueStoreInterface $adapter)
    {
        $this->cache = $adapter;
        $this->pool = new Pool($adapter);
    }
}
