<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class PdoMySQLProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('PDO')) {
            throw new Exception('ext-pdo is not installed.');
        }
        $client = new \PDO('mysql:host=mysql;port=3306;dbname=cache', 'root', '');
        parent::__construct(new \bdk\SimpleCache\Adapters\PdoMySQL($client));
    }
}
