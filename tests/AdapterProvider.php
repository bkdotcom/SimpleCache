<?php

namespace bdk\SimpleCache\Tests;

use bdk\SimpleCache\KeyValueStoreInterface;
use bdk\SimpleCache\Exception\Exception;

class AdapterProvider
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
     * @param KeyValueStoreInterface $adapter
     * @param string        $collectionName
     *
     * @throws Exception
     */
    public function __construct(KeyValueStoreInterface $adapter, $collectionName = 'collection')
    {
        $this->adapter = $adapter;
        $this->collectionName = $collectionName;
    }

    /**
     * @return KeyValueStore
     *
     * @throws Exception
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @return string
     */
    public function getCollectionName()
    {
        return $this->collectionName;
    }
}
