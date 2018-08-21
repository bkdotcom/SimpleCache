<?php

namespace bdk\SimpleCache\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * @group default
 * @group Memory
 */
class MemoryTest extends TestCase
{
    public function testLRU()
    {
        // all of the below 'valueX' have a (serialized) size of 13b
        $limit = strlen(serialize('value1')) + strlen(serialize('value2'));
        // cache should be able to handle only 2 of the below values at once
        $cache = new \bdk\SimpleCache\Adapters\Memory($limit);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');

        $this->assertEquals('value1', $cache->get('key1'));
        $this->assertEquals('value2', $cache->get('key2'));

        $cache->set('key3', 'value3');
        $cache->set('key1', 'value4');

        $this->assertEquals('value4', $cache->get('key1'));
        $this->assertEquals(false, $cache->get('key2'));
        $this->assertEquals('value3', $cache->get('key3'));
    }

    public function testLRUNoDoubleCount()
    {
        // all of the below 'valueX' have a (serialized) size of 13b
        $limit = strlen(serialize('value1')) + strlen(serialize('value2'));
        // cache should be able to handle only 2 of the below values at once
        $cache = new \bdk\SimpleCache\Adapters\Memory($limit);

        // writing to same key more than once should not double-count it
        $cache->set('key1', 'value1');
        $cache->set('key1', 'value2');
        $cache->set('key2', 'value3');

        $this->assertEquals('value2', $cache->get('key1'));
        $this->assertEquals('value3', $cache->get('key2'));
    }

    public function testLRUInParentAfterParentSet()
    {
        // all of the below 'valueX' have a (serialized) size of 13b
        $limit = strlen(serialize('value1')) + strlen(serialize('value2'));
        // cache should be able to handle only 2 of the below values at once
        $cache = new \bdk\SimpleCache\Adapters\Memory($limit);
        $collection = $cache->getCollection('collection');
        $cache->set('key1', 'value1');
        $collection->set('key2', 'value2');
        $this->assertEquals('value1', $cache->get('key1'));
        $this->assertEquals('value2', $collection->get('key2'));
        $cache->set('key3', 'value3');
        $this->assertEquals(false, $cache->get('key1'));
        $this->assertEquals('value2', $collection->get('key2'));
        $this->assertEquals('value3', $cache->get('key3'));
    }

    public function testLRUInCollectionAfterParentSet()
    {
        // all of the below 'valueX' have a (serialized) size of 13b
        $limit = strlen(serialize('value1')) + strlen(serialize('value2'));
        // cache should be able to handle only 2 of the below values at once
        $cache = new \bdk\SimpleCache\Adapters\Memory($limit);
        $collection = $cache->getCollection('collection');
        $collection->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $this->assertEquals('value1', $collection->get('key1'));
        $this->assertEquals('value2', $cache->get('key2'));
        $cache->set('key3', 'value3');
        $this->assertEquals(false, $collection->get('key1'));
        $this->assertEquals('value2', $cache->get('key2'));
        $this->assertEquals('value3', $cache->get('key3'));
    }

    public function testLRUInParentAfterCollectionSet()
    {
        // all of the below 'valueX' have a (serialized) size of 13b
        $limit = strlen(serialize('value1')) + strlen(serialize('value2'));
        // cache should be able to handle only 2 of the below values at once
        $cache = new \bdk\SimpleCache\Adapters\Memory($limit);
        $collection = $cache->getCollection('collection');
        $cache->set('key1', 'value1');
        $collection->set('key2', 'value2');
        $this->assertEquals('value1', $cache->get('key1'));
        $this->assertEquals('value2', $collection->get('key2'));
        $collection->set('key3', 'value3');
        $this->assertEquals(false, $cache->get('key1'));
        $this->assertEquals('value2', $collection->get('key2'));
        $this->assertEquals('value3', $collection->get('key3'));
    }

    public function testLRUInCollectionAfterCollectionSet()
    {
        // all of the below 'valueX' have a (serialized) size of 13b
        $limit = strlen(serialize('value1')) + strlen(serialize('value2'));
        // cache should be able to handle only 2 of the below values at once
        $cache = new \bdk\SimpleCache\Adapters\Memory($limit);
        $collection = $cache->getCollection('collection');
        $collection->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $this->assertEquals('value1', $collection->get('key1'));
        $this->assertEquals('value2', $cache->get('key2'));
        $collection->set('key3', 'value3');
        $this->assertEquals(false, $collection->get('key1'));
        $this->assertEquals('value2', $cache->get('key2'));
        $this->assertEquals('value3', $collection->get('key3'));
    }

    public function testClearInCollection()
    {
        $cache = new \bdk\SimpleCache\Adapters\Memory();
        $collection = $cache->getCollection('collection');
        $cache->set('key1', 'value1');
        $collection->set('key2', 'value2');
        // test that clear in collection works
        $result = $collection->clear();
        $this->assertTrue($result);
        $this->assertFalse($collection->get('key2'));
        // test that parent didn't get cleared
        $this->assertSame('value1', $cache->get('key1'));
        // verify that items of collection are gone entirely
        $object = new ReflectionObject($cache);
        $property = $object->getProperty('items');
        $property->setAccessible(true);
        $items = $property->getValue($cache);
        $this->assertEquals(array('key1'), array_keys($items));
        // verify that size has been updated correctly
        $property = $object->getProperty('size');
        $property->setAccessible(true);
        $size = $property->getValue($cache);
        $this->assertEquals(strlen(serialize('value1')), $size);
    }

    public function testClearInParent()
    {
        $cache = new \bdk\SimpleCache\Adapters\Memory();
        $collection = $cache->getCollection('collection');
        $cache->set('key1', 'value1');
        $collection->set('key2', 'value2');
        // test that clear in parent works
        $result = $cache->clear();
        $this->assertTrue($result);
        $this->assertFalse($cache->get('key1'));
        // test that collection got cleared as well
        $this->assertFalse($collection->get('key2'));
        // verify that items of parent & collection are gone entirely
        $object = new ReflectionObject($cache);
        $property = $object->getProperty('items');
        $property->setAccessible(true);
        $items = $property->getValue($cache);
        $this->assertEquals(array(), array_keys($items));
        // verify that size has been updated correctly
        $property = $object->getProperty('size');
        $property->setAccessible(true);
        $size = $property->getValue($cache);
        $this->assertEquals(0, $size);
    }
}
