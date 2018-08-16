<?php

namespace bdk\SimpleCache\Adapters\Collections;

use bdk\SimpleCache\Adapters\Apc as Adapter;
use bdk\SimpleCache\Adapters\Collections\Utils\PrefixKeys;

/**
 * APC adapter for a subset of data, accomplished by prefixing keys.
 */
class Apc extends PrefixKeys
{
    /**
     * Constructor
     *
     * @param Adapter $apcAdapter APC adapter
     * @param string  $name       collection name
     */
    public function __construct(Adapter $apcAdapter, $name)
    {
        parent::__construct($apcAdapter, 'collection:'.$name.':');
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        /*
            APCuIterator and apcDelete aren't publicly accessible using reflection to access
        */
        $reflectionMethod = new \ReflectionMethod($this->kvs, 'APCuIterator');
        $reflectionMethod->setAccessible(true);
        $iterator = $reflectionMethod->invoke($this->kvs, '/^'.\preg_quote($this->prefix, '/').'/', \APC_ITER_KEY);
        $reflectionMethod = new \ReflectionMethod($this->kvs, 'apcDelete');
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invoke($this->kvs, $iterator);
    }
}
