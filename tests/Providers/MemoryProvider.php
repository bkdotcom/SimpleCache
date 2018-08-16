<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Tests\AdapterProvider;

class MemoryProvider extends AdapterProvider
{
    public function __construct()
    {
        parent::__construct(new \bdk\SimpleCache\Adapters\Memory());
    }
}
