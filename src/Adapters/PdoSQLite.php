<?php

namespace bdk\SimpleCache\Adapters;

/**
 * SQLite adapter.
 */
class PdoSQLite extends PdoMySQL
{

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        $this->clearExpired();
        // SQLite-specific way (note the OR) to ignore insert-on-duplicate errors
        $statement = $this->client->prepare(
            'INSERT OR IGNORE INTO '.$this->table.' (k, v, t, e) VALUES (:key, :value, :token, :expiry)'
        );
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':token' => md5($value),
            ':expiry' => $this->expiry($expire),
        ));
        return $statement->rowCount() === 1;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->client->exec('DELETE FROM '.$this->table) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        if (empty($items)) {
            return array();
        }
        $expiry = $this->expiry($expire);
        $this->clearExpiredMaybe();
        // SQLite < 3.7.11 doesn't support multi-insert/replace!
        $statement = $this->client->prepare(
            'REPLACE INTO '.$this->table.' (k, v, t, e)
            VALUES (:key, :value, :token, :expiry)'
        );
        $success = array();
        foreach ($items as $key => $value) {
            $value = $this->serialize($value);
            $statement->execute(array(
                ':key' => $key,
                ':value' => $value,
                ':token' => md5($value),
                ':expiry' => $expiry,
            ));
            $success[$key] = (bool) $statement->rowCount();
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        $this->client->exec(
            'CREATE TABLE IF NOT EXISTS '.$this->table.' (
                k VARCHAR(255) NOT NULL PRIMARY KEY,
                v BLOB,
                t char(32) NOT NULL,
                e datetime NULL DEFAULT NULL,
                ct INT NULL DEFAULT 0,
                KEY e
            )'
        );
    }
}
