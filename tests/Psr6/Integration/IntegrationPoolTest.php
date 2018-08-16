<?php

namespace bdk\SimpleCache\Tests\Psr6\Integration;

use Cache\IntegrationTests\CachePoolTest;
use bdk\SimpleCache\Adapters\Couchbase;
use bdk\SimpleCache\Adapters\Collections\Couchbase as CouchbaseCollection;
use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Psr6\Pool;
use bdk\SimpleCache\Tests\AdapterTestProvider;
use bdk\SimpleCache\Tests\AdapterProviderTestInterface;

class IntegrationPoolTest extends CachePoolTest implements AdapterProviderTestInterface
{
    /**
     * @var KeyValueStore
     */
    protected $adapter;

    /**
     * @var string
     */
    protected $collectionName;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        parent::setUp();

        if ($this->adapter instanceof Couchbase || $this->adapter instanceof CouchbaseCollection) {
            $this->skippedTests['testExpiration'] = "Couchbase TTL can't be relied on with 1 second precision";
            $this->skippedTests['testHasItemReturnsFalseWhenDeferredItemIsExpired'] = "Couchbase TTL can't be relied on with 1 second precision";
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function suite()
    {
        $provider = new AdapterTestProvider(new static());
        return $provider->getSuite();
    }

    /**
     * {@inheritdoc}
     */
    public function setAdapter(KeyValueStoreInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * {@inheritdoc}
     */
    public function setCollectionName($name)
    {
        $this->collectionName = $name;
    }

    /**
     * @return Pool
     */
    public function createCachePool()
    {
        return new Pool($this->adapter);
    }
}
