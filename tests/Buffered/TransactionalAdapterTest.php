<?php

namespace bdk\SimpleCache\Tests\Buffered;

use bdk\SimpleCache\Buffered\Transactional;
use bdk\SimpleCache\Exception\UnbegunTransaction;
use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Tests\AdapterTest;

class TransactionalAdapterTest extends AdapterTest
{
    public function setAdapter(KeyValueStoreInterface $adapter)
    {
        $this->cache = new Transactional($adapter);
    }

    public function setUp()
    {
        parent::setUp();

        $this->cache->begin();
    }

    public function tearDown()
    {
        parent::tearDown();

        try {
            $this->cache->rollback();
        } catch (UnbegunTransaction $e) {
            // this is alright, guess we've terminated the transaction already
        }
    }
}
