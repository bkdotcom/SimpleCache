<?php

namespace bdk\SimpleCache\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @group default
 */
class NullTest extends TestCase
{

	public function __construct()
    {
        $this->adapter = new \bdk\SimpleCache\Adapters\Null();
    }

    public function testAdd()
    {
        $this->assertTrue($this->adapter->add('key', 'value'));
    }


    public function testCas()
    {
        $this->assertTrue($this->adapter->cas('token', 'key', 'value'));
    }

    public function testClear()
    {
        $this->assertTrue($this->adapter->clear());
    }

    public function testDecrement()
    {
        $this->assertSame(0, $this->adapter->decrement('key', 1, 0));
    }

    public function testDelete()
    {
        $this->assertFalse($this->adapter->delete('key'));
    }

    public function testDeleteMultiple()
    {
        $keys = array('key1','key2');
        $expect = array_fill_keys($keys, false);
    	$return = $this->adapter->deleteMultiple($keys);
    	$this->assertSame($expect, $return);
    }

    public function testGet()
    {
        $this->assertFalse($this->adapter->get('key', $token));
        $this->assertNull($token);
    }

    public function testGetCollection()
    {
        $return = $this->adapter->getCollection('foo');
        $this->assertInstanceOf(get_class($this->adapter), $return);
    }

    public function testGetInfo()
    {
    	$this->adapter->get('infokey');
    	$info = $this->adapter->getInfo();
    	$this->assertArraySubset(array(
    		'key' => 'infokey',
    		'code' => 'notExist',
    	), $info);
    }

    public function testIncrement()
    {
    	$this->assertSame(0, $this->adapter->increment('key', 1, 0));
    }

    public function testGetMultiple()
    {
    	$keys = array('key1','key2');
    	$expectReturn = array_fill_keys($keys, false);
    	$expectTokens = array_fill_keys($keys, null);
    	$return = $this->adapter->getMultiple($keys, $tokens);
    	$this->assertSame($expectReturn, $return);
    	$this->assertSame($expectTokens, $tokens);
	}

    public function testSet()
    {
        $this->assertTrue($this->adapter->set('key', 'value'));
    }

    public function testSetMultiple()
    {
    	$items = array(
    		'key1' => 'value1',
    		'key2' => 'value2',
    	);
    	$expect = array_fill_keys(array_keys($items), true);
    	$return = $this->adapter->setMultiple($items);
    	$this->assertSame($expect, $return);
    }
}
