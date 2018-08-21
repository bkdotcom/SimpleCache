<?php

namespace bdk\SimpleCache\Tests\Scale;

use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Tests\AdapterTest;

class StampedeProtectorAdapterTest extends AdapterTest
{
    /**
     * Time (in milliseconds) to protect against stampede.
     *
     * @var int
     */
    const SLA = 100;

    public function setAdapter(KeyValueStoreInterface $kvs)
    {
        $this->cache = new StampedeProtectorStub($kvs, static::SLA);
    }
}
