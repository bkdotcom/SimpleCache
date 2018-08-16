<?php

namespace bdk\SimpleCache\Tests;

use bdk\SimpleCache\KeyValueStoreInterface;
use PHPUnit\Framework\TestSuite;

interface AdapterProviderTestInterface
{
    /**
     * @return TestSuite
     */
    public static function suite();

    /**
     * This is where AdapterProvider will inject the adapter to.
     *
     * @param KeyValueStoreInterface $adapter
     */
    public function setAdapter(KeyValueStoreInterface $adapter);

    /**
     * @param string $name
     */
    public function setCollectionName($name);
}
