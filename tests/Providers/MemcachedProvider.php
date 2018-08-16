<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class MemcachedProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('Memcached')) {
            throw new Exception('ext-memcached is not installed.');
        }

        $client = new \Memcached();
        $client->addServer('memcached', '11211');

        parent::__construct(new \bdk\SimpleCache\Adapters\Memcached($client));
    }
}
