<?php

namespace bdk\SimpleCache\Tests\Providers;

use Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class FilesystemProvider extends AdapterProvider
{
    public function __construct()
    {
        $path = '/tmp/cache';
        if (!is_writable($path)) {
            throw new Exception($path.' is not writable.');
        }
        parent::__construct(new \bdk\SimpleCache\Adapters\Filesystem($path));
    }
}
