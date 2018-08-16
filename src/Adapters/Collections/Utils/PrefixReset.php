<?php

namespace bdk\SimpleCache\Adapters\Collections\Utils;

use bdk\SimpleCache\KeyValueStoreInterface;

/**
 *
 */
class PrefixReset extends PrefixKeys
{
    /**
     * @var string
     */
    protected $collectionName;

    /**
     * Constructor
     *
     * @param KeyValueStoreInterface $kvs  KeyValueStoreInterface instance
     * @param string                 $name collection name
     */
    public function __construct(KeyValueStoreInterface $kvs, $name)
    {
        $this->kvs = $kvs;
        $this->collectionName = $name;
        parent::__construct($kvs, $this->getPrefix());
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        /*
            This implementation kinda blows
            we're not flushing.. we're starting a new collection
            any values without expiry will linger...
        */
        $index = $this->kvs->increment($this->collectionName);
        $this->setPrefix($this->collectionName.':'.$index.':');
        return $index !== false;
    }

    /**
     * @return string
     */
    protected function getPrefix()
    {
        /*
            It's easy enough to just set a prefix to be used,
            but we can not flush only a prefix!
            Instead, we'll generate a unique prefix key, based on some name.
            If we want to flush, we just create a new prefix and use that one.
        */
        $index = $this->kvs->get($this->collectionName);
        if ($index === false) {
            $index = $this->kvs->set($this->collectionName, 1);
        }
        return $this->collectionName.':'.$index.':';
    }
}
