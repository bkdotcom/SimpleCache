<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class ApcProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!function_exists('apc_fetch') && !function_exists('apcu_fetch')) {
            throw new Exception('ext-apc(u) is not installed.');
        }

        parent::__construct(new \bdk\SimpleCache\Adapters\Apc());
    }
}
