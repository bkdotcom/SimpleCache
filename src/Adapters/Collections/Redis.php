<?php

namespace bdk\SimpleCache\Adapters\Collections;

use bdk\SimpleCache\Adapters\Redis as Adapter;

/**
 * Redis adapter for a subset of data, in a different database.
 */
class Redis extends Adapter
{
    /**
     * Constructor
     *
     * @param \Redis  $client   Redis client
     * @param integer $database database name
     */
    public function __construct($client, $database)
    {
        parent::__construct($client);
        $this->client->select($database);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->client->flushDB();
    }
}
