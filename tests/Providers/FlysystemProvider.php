<?php

namespace bdk\SimpleCache\Tests\Providers;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class FlysystemProvider extends AdapterProvider
{
    public function __construct()
    {
        $path = '/tmp/cache';

        if (!is_writable($path)) {
            throw new Exception($path.' is not writable.');
        }

        if (!class_exists('League\Flysystem\Filesystem')) {
            throw new Exception('Flysystem is not available.');
        }

        $adapter = new Local($path, LOCK_EX);
        $filesystem = new Filesystem($adapter);

        parent::__construct(new \bdk\SimpleCache\Adapters\Flysystem($filesystem));
    }
}
