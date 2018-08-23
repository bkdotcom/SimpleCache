<?php

namespace bdk\SimpleCache\Tests\Providers;

use Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class MySQLiProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('mysqli')) {
            throw new Exception('mysqli is not installed.');
        }
        $client = new \mysqli('mysql', 'root', '', 'cache');
        parent::__construct(new \bdk\SimpleCache\Adapters\MySQLi($client));
    }
}
