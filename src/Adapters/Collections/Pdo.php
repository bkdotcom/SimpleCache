<?php

namespace bdk\SimpleCache\Adapters\Collections;

use bdk\SimpleCache\Adapters\PdoBase as Adapter;
use bdk\SimpleCache\Adapters\Collections\Utils\PrefixKeys;
use PDO as PdoClient;

/**
 * SQL adapter for a subset of data, accomplished by prefixing keys.
 */
class Pdo extends PrefixKeys
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
     * @param Adapter   $kvs    PDO Adapter
     * @param PdoClient $client PDO instance
     * @param string    $table  table name
     * @param string    $name   collection name
     */
    public function __construct(Adapter $kvs, PdoClient $client, $table, $name)
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
        $statement = $this->client->prepare(
            'DELETE FROM '.$this->table.' WHERE k LIKE :key'
        );
        return $statement->execute(array(
            ':key' => $this->prefix.'%',
        ));
    }
}
