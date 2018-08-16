<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class RedisProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('Redis')) {
            throw new Exception('ext-redis is not installed.');
        }

        $client = new \Redis();
        $client->connect('redis', '6379');

        // Redis databases are numeric
        parent::__construct(new \bdk\SimpleCache\Adapters\Redis($client), 1);
    }
}
