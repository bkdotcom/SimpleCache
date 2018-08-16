<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class PdoSQLiteProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('PDO')) {
            throw new Exception('ext-pdo is not installed.');
        }
        $client = new \PDO('sqlite::memory:');
        parent::__construct(new \bdk\SimpleCache\Adapters\PdoSQLite($client));
    }
}
