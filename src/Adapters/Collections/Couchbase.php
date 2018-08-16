<?php

namespace bdk\SimpleCache\Adapters\Collections;

use bdk\SimpleCache\Adapters\Couchbase as Adapter;
use bdk\SimpleCache\Adapters\Collections\Utils\PrefixReset;

/**
 * Couchbase adapter for a subset of data, accomplished by prefixing keys.
 *
 * Couchbase supports multiple buckets. However, there's no overarching "server"
 * that can flush all the buckets (apart from looping all of them)
 * It may also not be possible to get into another bucket: they may have
 * different credentials.
 * And it's less trivial to "create" a new bucket. It could be done (although
 * not from the `CouchbaseBucket` we have in the adapter), but needs config.
 *
 * I'll implement collections similar to how they've been implemented for
 * Memcached: prefix keys & a reference value that can be changed to "flush"
 * the cache. If people want multiple different buckets, they can easily create
 * multiple Couchbase adapter objects.
 */
class Couchbase extends PrefixReset
{
    /**
     * Constructor
     *
     * @param Adapter $cache Couchbase adapter
     * @param string  $name  collection name
     */
    public function __construct(Adapter $cache, $name)
    {
        parent::__construct($cache, $name);
    }
}
