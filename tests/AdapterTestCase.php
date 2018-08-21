<?php

namespace bdk\SimpleCache\Tests;

use bdk\SimpleCache\KeyValueStoreInterface;
use PHPUnit\Framework\TestCase;

class AdapterTestCase extends TestCase implements AdapterProviderTestInterface
{
    /**
     * @var KeyValueStore
     */
    protected $cache;

    /**
     * @var string
     */
    protected $collectionName;

    public static function suite()
    {
        $provider = new AdapterTestProvider(new static());
        return $provider->getSuite();
    }

    public function setAdapter(KeyValueStoreInterface $kvs)
    {
        $this->cache = $kvs;
    }

    public function setCollectionName($name)
    {
        $this->collectionName = $name;
    }

    public function tearDown()
    {
        $this->cache->clear();
    }
}
