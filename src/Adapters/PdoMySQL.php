<?php

namespace bdk\SimpleCache\Adapters;

/**
 * PDO MySQL adapter.
 */
class PdoMySQL extends PdoBase
{

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        $this->clearExpired();
        // MySQL-specific way to ignore insert-on-duplicate errors
        $statement = $this->client->prepare(
            'INSERT IGNORE INTO '.$this->table.' (k, v, t, e) VALUES (:key, :value, :token, :expiry)'
        );
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':token' => \md5($value),
            ':expiry' => $this->expiry($expire),
        ));
        return $statement->rowCount() === 1;
    }

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
    public function set($key, $value, $expire = 0)
    {
        $statement = $this->client->prepare(
            'REPLACE INTO '.$this->table.' (k, v, t, e, ct) VALUES (:key, :value, :token, :expire, :calcTime)'
        );
        // $this->clearExpiredMaybe();
        $value = $this->serialize($value);
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':token' => \md5($value),
            ':expire' => $this->expiry($expire),
            ':calcTime' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : 0,
        ));
        // 1 = insert; 2 = update
        return $statement->rowCount() === 1 || $statement->rowCount() === 2;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        if (empty($items)) {
            return array();
        }
        $i = 1;
        $query = array();
        $params = array();
        $expiry = $this->expiry($expire);
        $this->clearExpiredMaybe();
        foreach ($items as $key => $value) {
            $value = $this->serialize($value);
            $query[] = "(:key$i, :value$i, :token$i, :expiry$i)";
            $params += array(
                ":key$i" => $key,
                ":value$i" => $value,
                ":token$i" => \md5($value),
                ":expiry$i" => $expiry,
            );
            ++$i;
        }
        $statement = $this->client->prepare(
            'REPLACE INTO '.$this->table.' (k, v, t, e)
            VALUES '.\implode(', ', $query)
        );
        $statement->execute($params);
        /*
            Can't compare with count($items) because rowCount could be 1 or 2,
            depending on if REPLACE was an INSERT or UPDATE.
        */
        $success = $statement->rowCount() > 0;
        return \array_fill_keys(\array_keys($items), $success);
    }

    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        $this->client->exec(
            "CREATE TABLE IF NOT EXISTS `".$this->table."` (
                `k` varbinary(255) NOT NULL,
                `v` longblob NOT NULL,
                `t` char(32) NOT NULL COMMENT 'token',
                `e` datetime DEFAULT NULL COMMENT 'expires',
                `ct` int(10) unsigned DEFAULT '0' COMMENT 'computation time (microseconds)',
                PRIMARY KEY (`k`),
                KEY `e` (`e`)
            )"
        );
    }
}
