<?php

namespace bdk\SimpleCache\Tests\Psr16;

use ArrayIterator;
use DateInterval;

class SimpleCacheTest extends Psr16TestCase
{
    public function testGet()
    {
        // set value in cache directly & test if it can be get from simplecache
        // interface
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->simplecache->get('key'));
    }

    public function testGetNonExisting()
    {
        $this->assertSame(null, $this->simplecache->get('key'));
    }

    public function testGetDefault()
    {
        $this->assertSame('default', $this->simplecache->get('key', 'default'));
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testGetException()
    {
        $this->simplecache->get(array());
    }

    public function testSet()
    {
        $success = $this->simplecache->set('key', 'value');
        $this->assertSame(true, $success);

        // check both cache & simplecache interface to confirm delete
        $this->assertSame('value', $this->cache->get('key'));
        $this->assertSame('value', $this->simplecache->get('key'));
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testSetException()
    {
        $this->simplecache->set(5, 5);
    }

    public function testSetExpired()
    {
        $success = $this->simplecache->set('key', 'value', -1);
        $this->assertSame(true, $success);

        $interval = new DateInterval('PT1S');
        $interval->invert = 1;
        $success = $this->simplecache->set('key2', 'value', $interval);
        $this->assertTrue($success);

        // check both cache & simplecache interface to confirm delete
        $this->assertFalse($this->cache->get('key'), get_class($this->cache));
        $this->assertNull($this->simplecache->get('key'));
        $this->assertFalse($this->cache->get('key2'));
        $this->assertNull($this->simplecache->get('key2'));
    }

    public function testSetFutureExpire()
    {
        $success = $this->simplecache->set('key', 'value', 1);
        $this->assertSame(true, $success);

        $success = $this->simplecache->set('key2', 'value', new DateInterval('PT1S'));
        $this->assertSame(true, $success);

        sleep(2);

        // check both cache & simplecache interface to confirm expire
        $this->assertSame(false, $this->cache->get('key'));
        $this->assertSame(null, $this->simplecache->get('key'));
        $this->assertSame(false, $this->cache->get('key2'));
        $this->assertSame(null, $this->simplecache->get('key2'));
    }

    public function testDelete()
    {
        // set value in cache, delete via simplecache interface & confirm it's
        // been deleted
        $this->cache->set('key', 'value');
        $success = $this->simplecache->delete('key');

        // check both cache & simplecache interface to confirm delete
        $this->assertSame(true, $success);
        $this->assertSame(false, $this->cache->get('key'));
        $this->assertSame(null, $this->simplecache->get('key'));
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testDeleteException()
    {
        $this->simplecache->delete(new \stdClass());
    }

    public function testClear()
    {
        // some values that should be gone when we clear cache...
        $this->cache->set('key', 'value');
        $this->simplecache->set('key2', 'value');

        $success = $this->simplecache->clear();

        // check both cache & simplecache interface to confirm everything's been
        // wiped out
        $this->assertSame(true, $success);
        $this->assertSame(false, $this->cache->get('key'));
        $this->assertSame(null, $this->simplecache->get('key'));
        $this->assertSame(false, $this->cache->get('key2'));
        $this->assertSame(null, $this->simplecache->get('key2'));
    }

    public function testGetMultiple()
    {
        $this->cache->setMultiple(array('key' => 'value', 'key2' => 'value'));
        $results = $this->simplecache->getMultiple(array('key', 'key2', 'key3'));
        $this->assertSame(array('key' => 'value', 'key2' => 'value', 'key3' => null), $results);
    }

    public function testGetMultipleDefault()
    {
        $this->assertSame(array('key' => 'default'), $this->simplecache->getMultiple(array('key'), 'default'));
    }

    public function testGetMultipleTraversable()
    {
        $this->cache->setMultiple(array('key' => 'value', 'key2' => 'value'));
        $iterator = new ArrayIterator(array('key', 'key2', 'key3'));
        $results = $this->simplecache->getMultiple($iterator);
        $this->assertSame(array('key' => 'value', 'key2' => 'value', 'key3' => null), $results);
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testGetMultipleException()
    {
        $this->simplecache->getMultiple(null);
    }

    public function testSetMultiple()
    {
        $success = $this->simplecache->setMultiple(array('key' => 'value', 'key2' => 'value'));
        $this->assertSame(true, $success);

        $results = $this->cache->getMultiple(array('key', 'key2'));
        $this->assertSame(array('key' => 'value', 'key2' => 'value'), $results);
    }

    public function testSetMultipleTraversable()
    {
        $iterator = new ArrayIterator(array('key' => 'value', 'key2' => 'value'));
        $success = $this->simplecache->setMultiple($iterator);
        $this->assertSame(true, $success);

        $results = $this->cache->getMultiple(array('key', 'key2'));
        $this->assertSame(array('key' => 'value', 'key2' => 'value'), $results);
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testSetMultipleException()
    {
        $this->simplecache->setMultiple(123.456);
    }

    public function testDeleteMultiple()
    {
        $this->cache->setMultiple(array('key' => 'value', 'key2' => 'value'));
        $success = $this->simplecache->deleteMultiple(array('key', 'key2'));
        $this->assertSame(true, $success);
        $this->assertSame(array(), $this->cache->getMultiple(array('key', 'key2')));
    }

    public function testDeleteMultipleTraversable()
    {
        $this->cache->setMultiple(array('key' => 'value', 'key2' => 'value'));
        $iterator = new ArrayIterator(array('key', 'key2'));
        $success = $this->simplecache->deleteMultiple($iterator);
        $this->assertSame(true, $success);
        $this->assertSame(array(), $this->cache->getMultiple(array('key', 'key2')));
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testDeleteMultipleException()
    {
        $this->simplecache->deleteMultiple(123);
    }

    public function testHas()
    {
        $this->cache->set('key', 'value');

        $this->assertSame(true, $this->simplecache->has('key'));
        $this->assertSame(false, $this->simplecache->has('key2'));
    }

    /**
     * @expectedException \Psr\SimpleCache\InvalidArgumentException
     */
    public function testHasException()
    {
        $this->simplecache->has(true);
    }
}
