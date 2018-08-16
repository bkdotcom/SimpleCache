<?php

namespace bdk\SimpleCache\Tests\Collections;

use bdk\SimpleCache\Tests\AdapterTest;

class CollectionsAdapterTest extends AdapterTest
{
    public function setUp()
    {
        parent::setUp();

        // Do this here instead of in setAdapter, because that runs before
        // the test suite, but we want a new collection for every single test
        $this->cache = $this->cache->getCollection($this->collectionName);
    }

    public function testCollectionGetParentKey()
    {
        $this->markTestSkipped(
            'This test is invalid for collections derived from collections. '.
            "They don't keep nesting, there's only server/collection."
        );
    }

    public function testCollectionGetCollectionKey()
    {
        $this->markTestSkipped(
            'This test is invalid for collections derived from collections. '.
            "They don't keep nesting, there's only server/collection."
        );
    }

    public function testCollectionSetSameKey()
    {
        $this->markTestSkipped(
            'This test is invalid for collections derived from collections. '.
            "They don't keep nesting, there's only server/collection."
        );
    }

    public function testCollectionClearParent()
    {
        $this->markTestSkipped(
            'This test is invalid for collections derived from collections. '.
            "They don't keep nesting, there's only server/collection."
        );
    }

    public function testCollectionClearCollection()
    {
        $this->markTestSkipped(
            'This test is invalid for collections derived from collections. '.
            "They don't keep nesting, there's only server/collection."
        );
    }
}
