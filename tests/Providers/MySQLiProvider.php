<?php

namespace bdk\SimpleCache\Tests\Providers;

use Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class MySQLiProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('mysqli')) {
            throw new Exception('misqli is not installed.');
        }
        $client = new \mysqli('127.0.0.1', 'web', 'd5T*sk7YqbZP', 'cache');
        parent::__construct(new \bdk\SimpleCache\Adapters\MySQLi($client));
    }
}
