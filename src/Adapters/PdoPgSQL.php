<?php

namespace bdk\SimpleCache\Adapters;

/**
 * PostgreSQL adapter. Basically just a wrapper over \PDO, but in an
 * exchangeable (KeyValueStore) interface.
 */
class PdoPgSQL extends PdoBase
{
    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->client->exec('TRUNCATE TABLE '.$this->table) !== false;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function get($key, &$token = null)
    {
        $return = parent::get($key, $token);
        if ($token !== null) {
            // BYTEA data return streams -
            // we actually need the data in serialized format, not a stream
            $token = $this->serialize($return);
        }
        return $return;
    }
    */

    /**
     * {@inheritdoc}
     */
    /*
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $return = parent::getMultiple($keys, $tokens);
        foreach ($return as $key => $value) {
            // BYTEA data return streams -
            // we actually need the data in serialized format, not s stream
            $tokens[$key] = $this->serialize($value);
        }

        return $return;
    }
    */

    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        $this->client->exec(
            'CREATE TABLE IF NOT EXISTS '.$this->table.' (
                k character varying NOT NULL PRIMARY KEY,
                v text NOT NULL,
                t character(32) NOT NULL,
                e timestamp without time zone,
                ct integer
            )'
        );
        $this->client->exec('CREATE INDEX IF NOT EXISTS e_index ON '.$this->table.' (e)');
    }

    /**
     * {@inheritdoc}
     */
    /*
    protected function unserialize($value)
    {
        // BYTEA data return streams. Even though it's not how init() will
        // configure the DB by default, it could be used instead!
        if (\is_resource($value)) {
            $value = \stream_get_contents($value);
        }
        return parent::unserialize($value);
    }
    */
}
