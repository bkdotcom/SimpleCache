<?php

namespace bdk\SimpleCache\Adapters\Collections;

use bdk\SimpleCache\Adapters\MySQLi as Adapter;
use bdk\SimpleCache\Adapters\Collections\Utils\PrefixKeys;
use mysqli as mysqliClient;

/**
 * SQL adapter for a subset of data, accomplished by prefixing keys.
 */
class MySQLi extends PrefixKeys
{
    /**
     * @var PDO
     */
    protected $client;

    /**
     * @var string
     */
    protected $table;

    /**
     * Constructor
     *
     * @param Adapter      $kvs    MySQLi Adapter
     * @param mysqliClient $client mysqli client instance
     * @param string       $table  table name
     * @param string       $name   collection name
     */
    public function __construct(Adapter $kvs, mysqliClient $client, $table, $name)
    {
        parent::__construct($kvs, 'collection:'.$name.':');
        $this->client = $client;
        $this->table = $table;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        // deleting key with a prefixed LIKE should be fast, they're indexed
        $stmt = $this->client->prepare(
            'DELETE FROM '.$this->table.' WHERE k LIKE ?'
        );
        $key = $this->prefix.'%';
        $stmt->bind_param('s', $key);
        $success = $stmt->execute() !== false;
        $stmt->close();
        return $success;

    }
}
