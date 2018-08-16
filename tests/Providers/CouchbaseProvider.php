<?php

namespace bdk\SimpleCache\Tests\Providers;

use bdk\SimpleCache\Exception\Exception;
use bdk\SimpleCache\Tests\AdapterProvider;

class CouchbaseProvider extends AdapterProvider
{
    public function __construct()
    {
        if (!class_exists('CouchbaseCluster')) {
            throw new Exception('ext-couchbase is not installed.');
        }

        $authenticator = new \Couchbase\PasswordAuthenticator();
        $authenticator->username('Administrator')->password('password');

        $cluster = new \CouchbaseCluster('couchbase://couchbase:11210?detailed_errcodes=1');
        $cluster->authenticate($authenticator);
        $bucket = $cluster->openBucket('default');

        $healthy = $this->waitForHealthyServer($bucket);

        parent::__construct(new \bdk\SimpleCache\Adapters\Couchbase($bucket, !$healthy));
    }

    /**
     * Wait 10 seconds should nodes not be healthy; they may be warming up
     *
     * @param \CouchbaseBucket $bucket
     *
     * @return bool
     */
    protected function waitForHealthyServer(\CouchbaseBucket $bucket)
    {
        for ($i = 0; $i < 10; $i++) {
            $healthy = true;
            $info = $bucket->manager()->info();
            foreach ($info['nodes'] as $node) {
                $healthy = $healthy && $node['status'] === 'healthy';
            }

            if ($healthy) {
                return true;
            }

            sleep(1);
        }

        return false;
    }
}
