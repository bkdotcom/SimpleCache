<?php

namespace bdk\SimpleCache\Tests;

class AdapterTest extends AdapterTestCase
{
    public function testGetAndSet()
    {
        $return = $this->cache->set('test key', 'value');

        $this->assertTrue($return);
        $this->assertEquals('value', $this->cache->get('test key'));
        $info = $this->cache->getInfo();
        $this->assertArraySubset(array(
            'code' => 'hit',
            'key' => 'test key',
        ), $info);
        $this->assertNotEmpty($info['token']);
        $this->assertInternalType('float', $info['microtime']);
    }

    public function testGetVeryLongKeys()
    {
        $return = $this->cache->set('this-is-turning-out-to-be-a-rather-unusually-long-key', 'value');
        $this->assertTrue($return);
        $this->assertEquals('value', $this->cache->get('this-is-turning-out-to-be-a-rather-unusually-long-key'));

        $return = $this->cache->set('12345678901234567890123456789012345678901234567890', 'value');
        $this->assertTrue($return);
        $this->assertEquals('value', $this->cache->get('12345678901234567890123456789012345678901234567890'));
    }

    public function testGetFail()
    {
        $this->assertFalse($this->cache->get('test key'), get_class($this->cache));
        $info = $this->cache->getInfo();
        $this->assertEquals('notExist', $info['code']);
    }

    public function testGetNonReferential()
    {
        // this is mostly for Memory adapter - other stores probably aren't at risk

        $object = new \stdClass();
        $object->value = 'test';
        $this->cache->set('test key', $object);

        // clone the object because we'll be messing with it ;)
        $comparison = clone $object;

        // changing the object after it's been cached shouldn't affect cache
        $object->value = 'updated-value';
        $fromCache = $this->cache->get('test key');
        $this->assertEquals($comparison, $fromCache);

        // changing the value we got from cache shouldn't change what's in cache
        $fromCache->value = 'updated-value-2';
        $fromCache2 = $this->cache->get('test key');
        $this->assertNotEquals($comparison, $fromCache);
        $this->assertEquals($comparison, $fromCache2);
    }

    public function testGetMultiple()
    {
        $items = array(
            'test key' => 'value',
            'key2' => 'value2',
        );

        foreach ($items as $key => $value) {
            $this->cache->set($key, $value);
        }

        $this->assertEquals($items, $this->cache->getMultiple(array_keys($items)));

        // requesting non-existing keys
        $this->assertEquals(array(), $this->cache->getMultiple(array('key3')));
        $this->assertEquals(array('key2' => 'value2'), $this->cache->getMultiple(array('key2', 'key3')));
    }

    public function testGetSet()
    {
        $return = $this->cache->getSet('test key', function () {
            return 'new value';
        });
        $this->assertEquals('new value', $return);
        $this->assertEquals('new value', $this->cache->get('test key'));
        // test that getter function now called when existing value not expired
        $return = $this->cache->getSet('test key', function () {
            return 'newer value';
        });
        $this->assertEquals('new value', $return);
        $this->assertEquals('new value', $this->cache->get('test key'));
    }

    public function testGetNoCasTokens()
    {
        $this->cache->get('test key', $token);
        $info = $this->cache->getInfo();
        $this->assertNull($token);
        $this->assertNull($info['token']);

        $this->cache->getMultiple(array('test key'), $tokens);
        $this->assertSame(array(), $tokens);
    }

    public function testGetCasTokensFromFalse()
    {
        // 'false' is also a value, with a token
        $return = $this->cache->set('test key', false);

        $this->assertTrue($return);

        $this->assertFalse($this->cache->get('test key', $token));
        $info = $this->cache->getInfo();
        $this->assertNotNull($token);
        $this->assertNotNull($info['token']);

        $this->assertEquals(array(
            'test key' => false
        ), $this->cache->getMultiple(array('test key'), $tokens));
        $this->assertNotNull($tokens['test key']);
    }

    public function testGetCasTokensOverridesTokenValue()
    {
        $token = 'some-value';
        $tokens = array('some-value');

        $this->assertFalse($this->cache->get('test key', $token));
        $this->assertNull($token);

        $this->assertSame(array(), $this->cache->getMultiple(array('test key'), $tokens));
        $this->assertSame(array(), $tokens);
    }

    public function testSetExpired()
    {
        $return = $this->cache->set('test key', 'value', time() - 2);
        $this->assertTrue($return);
        $this->assertFalse($this->cache->get('test key'));
        $info = $this->cache->getInfo();
        $this->assertArraySubset(array(
            // 'code' => 'notExist',    // setting a value to expired likely didn't set the value
            // 'expiredValue' => 'value',
            'key' => 'test key',
        ), $info);

        // test if we can add to, but not replace or touch an expired value; it
        // should be treated as if the value doesn't exist)
        /*
        $return = $this->cache->replace('test key', 'value');
        $this->assertEquals(false, $return);
        $return = $this->cache->touch('test key', time() + 2);
        $this->assertEquals(false, $return);
        */
        $return = $this->cache->add('test key', 'value');
        $this->assertTrue($return);
    }

    public function testSetMultiple()
    {
        $items = array(
            'test key' => 'value',
            'key2' => 'value2',
        );
        $return = $this->cache->setMultiple($items);
        $expect = array_fill_keys(array_keys($items), true);
        $this->assertEquals($expect, $return);
        $this->assertEquals('value', $this->cache->get('test key'));
        $this->assertEquals('value2', $this->cache->get('key2'));
    }

    public function testSetMultiIntegerKeys()
    {
        $items = array(
            '0' => 'value',
            '1' => 'value2',
        );
        $return = $this->cache->setMultiple($items);
        $expect = array_fill_keys(array_keys($items), true);
        $this->assertEquals($expect, $return);
        $this->assertEquals('value', $this->cache->get('0'));
        $this->assertEquals('value2', $this->cache->get('1'));
    }

    public function testSetMultiExpired()
    {
        $items = array(
            'test key' => 'value',
            'key2' => 'value2',
        );
        $return = $this->cache->setMultiple($items, time() - 2);
        $expect = array_fill_keys(array_keys($items), true);
        $this->assertEquals($expect, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
        $this->assertEquals(false, $this->cache->get('key2'));
    }

    public function testGetAndSetMultiVeryLongKeys()
    {
        $items = array(
            'this-is-turning-out-to-be-a-rather-unusually-long-key' => 'value',
            '12345678901234567890123456789012345678901234567890' => 'value',
        );
        $this->cache->setMultiple($items);
        $this->assertEquals($items, $this->cache->getMultiple(array_keys($items)));
    }

    public function testDelete()
    {
        $this->cache->set('test key', 'value');
        $return = $this->cache->delete('test key');
        $this->assertTrue($return);
        $this->assertFalse($this->cache->get('test key'));
        $info = $this->cache->getInfo();
        $this->assertEquals('notExist', $info['code']);
        // delete non-existing key
        $return = $this->cache->delete('key2');
        $this->assertFalse($return);
        $this->assertFalse($this->cache->get('key2'));
        $info = $this->cache->getInfo();
        $this->assertEquals('notExist', $info['code']);
    }

    public function testDeleteMultiple()
    {
        $items = array(
            'test key' => 'value',
            'key2' => 'value2',
        );

        $this->cache->setMultiple($items);
        $return = $this->cache->deleteMultiple(array_keys($items));

        $expect = array_fill_keys(array_keys($items), true);
        $this->assertSame($expect, $return);
        $this->assertFalse($this->cache->get('test key'));
        $this->assertFalse($this->cache->get('key2'));

        // delete all non-existing key (they've been deleted already)
        $return = $this->cache->deleteMultiple(array_keys($items));

        $expect = array_fill_keys(array_keys($items), false);
        $this->assertEquals($expect, $return);
        $this->assertFalse($this->cache->get('test key'));
        $this->assertFalse($this->cache->get('key2'));

        // delete existing & non-existing key
        $this->cache->set('test key', 'value');
        $return = $this->cache->deleteMultiple(array_keys($items));

        $expect = array_fill_keys(array_keys($items), false);
        $expect['test key'] = true;
        $this->assertEquals($expect, $return);
        $this->assertFalse($this->cache->get('test key'));
        $this->assertFalse($this->cache->get('key2'));
    }

    public function testAdd()
    {
        $return = $this->cache->add('test key', 'value');
        $this->assertTrue($return);
        $this->assertEquals('value', $this->cache->get('test key'));
    }

    public function testAddFail()
    {
        $this->cache->set('test key', 'value');
        $return = $this->cache->add('test key', 'value-2');
        $this->assertFalse($return);
        $this->assertEquals('value', $this->cache->get('test key'));
    }

    public function testAddExpired()
    {
        $return = $this->cache->add('test key', 'value', time() - 2);
        $this->assertTrue($return);
        $this->assertFalse($this->cache->get('test key'));
    }

    /*
    public function testReplace()
    {
        $this->cache->set('test key', 'value');
        $return = $this->cache->replace('test key', 'value-2');

        $this->assertEquals(true, $return);
        $this->assertEquals('value-2', $this->cache->get('test key'));
    }

    public function testReplaceFail()
    {
        $return = $this->cache->replace('test key', 'value');

        $this->assertEquals(false, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
    }

    public function testReplaceExpired()
    {
        $this->cache->set('test key', 'value');
        $return = $this->cache->replace('test key', 'value', time() - 2);

        $this->assertEquals(true, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
    }

    public function testReplaceSameValue()
    {
        $this->cache->set('test key', 'value');
        $return = $this->cache->replace('test key', 'value');

        $this->assertEquals(true, $return);
    }
    */

    public function testCas()
    {
        $this->cache->set('test key', 'value');

        // token via get()
        $this->cache->get('test key', $token);
        $return = $this->cache->cas($token, 'test key', 'updated-value');

        $this->assertTrue($return);
        $this->assertEquals('updated-value', $this->cache->get('test key'));

        // token via getMultiple()
        $this->cache->getMultiple(array('test key'), $tokens);
        $token = $tokens['test key'];
        $return = $this->cache->cas($token, 'test key', 'updated-value-2');

        $this->assertTrue($return);
        $this->assertEquals('updated-value-2', $this->cache->get('test key'));
    }

    public function testCasFail()
    {
        $this->cache->set('test key', 'value');

        // get CAS token
        $this->cache->get('test key', $token);

        // write something else to the same key in the meantime
        $this->cache->set('test key', 'updated-value');

        // attempt CAS, which should now fail (token no longer valid)
        $return = $this->cache->cas($token, 'test key', 'updated-value-2');

        $this->assertFalse($return);
        $this->assertEquals('updated-value', $this->cache->get('test key'));
    }

    /*
    // I see no reason that cas should fail if there's no value currently in cache
    // CAS prevents __overwriting__ data.
    public function testCasFailIfDeleted()
    {
        $this->cache->set('test key', 'value');

        // get CAS token
        $this->cache->get('test key', $token);

        // delete that key in the meantime
        $this->cache->delete('test key');

        // attempt CAS, which should now fail (token no longer valid)
        $return = $this->cache->cas($token, 'test key', 'updated-value-2');

        $this->assertEquals(false, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
    }
    */

    public function testCasExpired()
    {
        $this->cache->set('test key', 'value');

        // token via get()
        $this->cache->get('test key', $token);
        $return = $this->cache->cas($token, 'test key', 'updated-value', time() - 2);

        $this->assertTrue($return);
        $this->assertFalse($this->cache->get('test key'));
    }

    public function testCasSameValue()
    {
        $this->cache->set('test key', 'value');
        $this->cache->get('test key', $token);
        $return = $this->cache->cas($token, 'test key', 'value');
        $this->assertTrue($return);
    }

    /*
    // I see no reason that cas should fail if there's no value currently in cache
    // CAS prevents __overwriting__ data.
    public function testCasNoOriginalValue()
    {
        $this->cache->get('test key', $token);
        $return = $this->cache->cas($token, 'test key', 'value');

        $this->assertEquals(false, $return, 'cas did not return false');
        $this->assertEquals(false, $this->cache->get('test key'));
    }
    */

    public function testIncrement()
    {
        // set initial value
        $return = $this->cache->increment('test key', 1, 1);

        $this->assertSame(1, $return);
        $this->assertSame(1, $this->cache->get('test key'));

        $return = $this->cache->increment('key2', 1, 0);

        $this->assertSame(0, $return);
        $this->assertSame(0, $this->cache->get('key2'));

        // increment
        $return = $this->cache->increment('test key', 1, 1);

        $this->assertSame(2, $return);
        $this->assertSame(2, $this->cache->get('test key'));
    }

    public function testIncrementFail()
    {
        /*
        // negative increments are allowed
        $return = $this->cache->increment('test key', -1, 0);
        $this->assertEquals(false, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
        */

        /*
        // negative initial values are allowed
        $return = $this->cache->increment('test key', 5, -2);
        $this->assertEquals(false, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
        */

        // non-numeric value in cache
        $this->cache->set('test key', 'value');
        $return = $this->cache->increment('test key', 1, 1);
        $this->assertFalse($return);
        $this->assertEquals('value', $this->cache->get('test key'));
    }

    public function testIncrementExpired()
    {
        // set initial value
        $return = $this->cache->increment('test key', 1, 1, time() - 2);

        $this->assertSame(1, $return, get_class($this->cache));
        $this->assertFalse($this->cache->get('test key'), 'should be expired 1');

        // set initial value (not expired) & increment (expired)
        $this->cache->increment('test key', 1, 1);
        $return = $this->cache->increment('test key', 1, 1, time() - 2);

        $this->assertSame(2, $return);
        $this->assertFalse($this->cache->get('test key'), 'should be expired 2');
    }

    public function testDecrement()
    {
        // set initial value
        $return = $this->cache->decrement('test key', 1, 1);
        $this->assertSame(1, $return);
        $this->assertSame(1, $this->cache->get('test key'));

        $return = $this->cache->decrement('key2', 1, 0);
        $this->assertSame(0, $return);
        $this->assertSame(0, $this->cache->get('key2'));

        // decrement
        $return = $this->cache->decrement('test key', 1, 1);
        $this->assertSame(0, $return);
        $this->assertSame(0, $this->cache->get('test key'));

        // decrement again (we allow < 0)
        $return = $this->cache->decrement('test key', 1, 1);
        $this->assertSame(-1, $return);
        $this->assertSame(-1, $this->cache->get('test key'));
    }

    public function testDecrementFail()
    {
        /*
        // we'll allow negative decrement
        $return = $this->cache->decrement('test key', -1, 0);
        $this->assertEquals(false, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
        */

        /*
        // we'll allow negative initial val
        $return = $this->cache->decrement('test key', 5, -2);
        $this->assertEquals(false, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
        */

        // non-numeric value in cache
        $this->cache->set('test key', 'value');
        $return = $this->cache->increment('test key', 1, 1);
        $this->assertFalse($return);
        $this->assertEquals('value', $this->cache->get('test key'));
    }

    public function testDecrementExpired()
    {
        // set initial value
        $return = $this->cache->decrement('test key', 1, 1, time() - 2);

        $this->assertSame(1, $return);
        $this->assertFalse($this->cache->get('test key'));

        // set initial value (not expired) & increment (expired)
        $this->cache->decrement('test key', 1, 1);
        $return = $this->cache->decrement('test key', 1, 1, time() - 2);

        $this->assertSame(0, $return);
        $this->assertFalse($this->cache->get('test key'));
    }

    /*
    public function testTouch()
    {
        $this->cache->set('test key', 'value');

        // not yet expired
        $return = $this->cache->touch('test key', time() + 2);
        $this->assertEquals(true, $return);
        $this->assertEquals('value', $this->cache->get('test key'));
    }

    public function testTouchExpired()
    {
        $this->cache->set('test key', 'value');

        // expired
        $return = $this->cache->touch('test key', time() - 2);
        $this->assertEquals(true, $return);
        $this->assertEquals(false, $this->cache->get('test key'));
    }
    */

    public function testClear()
    {
        $this->cache->set('test key', 'value');
        $return = $this->cache->clear();

        $this->assertTrue($return);
        $this->assertFalse($this->cache->get('test key'));
    }

    public function testCollectionGetParentKey()
    {
        $collection = $this->cache->getCollection($this->collectionName);

        $this->cache->set('key', 'value');

        $this->assertEquals('value', $this->cache->get('key'));
        $this->assertFalse($collection->get('key'));
    }

    public function testCollectionGetCollectionKey()
    {
        $collection = $this->cache->getCollection($this->collectionName);

        $collection->set('key', 'value');
        $this->assertFalse($this->cache->get('key'));
        $this->assertEquals('value', $collection->get('key'));

        $collection->clear();
    }

    public function testCollectionSetSameKey()
    {
        $collection = $this->cache->getCollection($this->collectionName);

        $this->cache->set('key', 'value');
        $collection->set('key', 'other-value');

        $this->assertEquals('value', $this->cache->get('key'));
        $this->assertEquals('other-value', $collection->get('key'));

        $collection->clear();
    }

    public function testCollectionClearParent()
    {
        $collection = $this->cache->getCollection($this->collectionName);

        $this->cache->set('key', 'value');
        $collection->set('key', 'other-value');
        $this->cache->clear();

        $this->assertFalse($this->cache->get('key'));
        $this->assertFalse($collection->get('key'));

        $collection->clear();
    }

    public function testCollectionClearCollection()
    {
        $collection = $this->cache->getCollection($this->collectionName);

        $this->cache->set('key', 'value');
        $collection->set('key', 'other-value');

        $collection->clear();

        $this->assertEquals('value', $this->cache->get('key'));
        $this->assertFalse($collection->get('key'));
    }
}
