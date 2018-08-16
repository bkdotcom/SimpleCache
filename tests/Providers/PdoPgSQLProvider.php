<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class PdoPgSQLProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('PDO')) {
            throw new Exception('ext-pdo is not installed.');
        }
        $client = new \PDO('pgsql:host=postgresql;port=5432;dbname=cache', 'postgres', '');
        parent::__construct(new \bdk\SimpleCache\Adapters\PdoPgSQL($client));
    }
}
