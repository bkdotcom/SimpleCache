<?php

namespace bdk\SimpleCache\Adapters;

use mysqli as client;
use bdk\SimpleCache\Adapters\Collections\MySQLi as Collection;

/**
 * PDO MySQL adapter.
 */
class MySQLi extends Base
{

    const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @var client
     */
    protected $client;

    /**
     * Constructor
     *
     * @param client $client mysqli client
     * @param string $table  cache table name
     */
    public function __construct(client $client, $table = 'cache')
    {
        $this->client = $client;
        $this->table = $table;
        $this->init();
        $this->clearExpired();
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        $token = \md5($value);
        $expiry = $this->expiry($expire);
        $token =
        $this->clearExpired();
        // MySQL-specific way to ignore insert-on-duplicate errors
        $stmt = $this->client->prepare(
            'INSERT IGNORE INTO '.$this->table.' (k, v, t, e) VALUES (?, ?, ?, ?)'
        );

        $stmt->bind_param('ssss', $key, $value, $token, $expiry);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows === 1;
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        $tokenNew = \md5($value);
        $expiry = $this->expiry($expire);
        $calcTime = $this->lastGetInfo['key'] == $key
            ? \round((\microtime(true) - $this->lastGetInfo['microtime']) * 1000000)
            : null;
        $stmt = $this->client->prepare(
            'UPDATE '.$this->table.'
            SET v = ?, t = ?, e = ?, ct = ?
            WHERE k = ? AND t = ?'
        );
        $stmt->bind_param(
            'sssiss',
            $value,
            $tokenNew,
            $expiry,
            $calcTime,
            $key,
            $token
        );
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        if ($affectedRows === 1) {
            return true;
        }
        /*
            affected_rows =  0 -> confirm whether updated
                if the value we've just cas'ed was the same as the replacement, as
                well as the same expiration time, affected_rows will have been 0
                even though the operation was a success
        */
        $stmt = $this->client->prepare(
            'SELECT v, t, e FROM '.$this->table.'
            WHERE k = ?'
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        // bind result variables
        $stmt->bind_result($v, $t, $e);
        $success = $stmt->fetch();
        $stmt->close();
        if (!$success) {
            // key not in db -> add
            return $this->add($key, $this->unserialize($value), $expire);
        } elseif ($v == $value && $t == $tokenNew && $e == $expiry) {
            // looks like it was updated
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->client->query('TRUNCATE TABLE '.$this->table) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $stmt = $this->client->prepare(
            'DELETE FROM '.$this->table.' WHERE k = ?'
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows === 1;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        if (empty($keys)) {
            return array();
        }
        // we'll need these to figure out which could not be deleted...
        $items = $this->getMultiple($keys);
        // escape input, can't bind multiple params for IN()
        $escaped = \array_map(array($this->client, 'real_escape_string'), $keys);
        $response = $this->client->query(
            'DELETE FROM '.$this->table.' WHERE k IN ("'.\implode('","', $escaped).'")'
        );
        $success = $response !== false;
        $success = \array_fill_keys($keys, $success);
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $items)) {
                $success[$key] = false;
            }
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $token = null;
        $this->resetLastGetInfo($key);
        $stmt = $this->client->prepare(
            'SELECT v, t, e, ct
            FROM '.$this->table.'
            WHERE k = ?' //  AND (e IS NULL OR e > :expire)
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $data = array(
            // 'v' => null,
            // 't' => null,
            // 'e' => null,
            // 'ct' => null,
        );
        $stmt->bind_result($data['v'], $data['t'], $data['e'], $data['ct']);
        $success = $stmt->fetch();
        $stmt->close();
        if (!$success) {
            return false;
        }
        $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
        $isExpired = $data['e'] && $data['e'] < \gmdate(self::DATETIME_FORMAT, \microtime(true) - $data['ct']/1000000 * \log($rand));
        $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
            'calcTime' => $data['ct'],
            'code' => 'hit',
            'expiry' => $data['e'],
            // 'expiryOriginal' => $data['eo'],
            'token' => $data['t'],
        ));
        if ($isExpired) {
            $this->lastGetInfo['code'] = 'expired';
            $this->lastGetInfo['expiredValue'] = $this->unserialize($data['v']);
            return false;
        }
        $token = $data['t'];
        return $this->unserialize($data['v']);
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        return new Collection($this, $this->client, $this->table, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $tokens = array();
        if (empty($keys)) {
            return array();
        }
        $escaped = \array_map(array($this->client, 'real_escape_string'), $keys);
        $stmt = $this->client->prepare(
            'SELECT k, v, t
            FROM '.$this->table.'
            WHERE
                k IN ("'.\implode('","', $escaped).'") AND
                (e IS NULL OR e > ?)'
        );
        $expiry = \gmdate(self::DATETIME_FORMAT);
        $stmt->bind_param('s', $expiry);
        $stmt->execute();
        $stmt->bind_result($k, $v, $t);
        $return = array();
        $tokens = array();
        while ($stmt->fetch()) {
            $tokens[$k] = $t;
            $return[$k] = $this->unserialize($v);
        }
        $stmt->close();
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $stmt = $this->client->prepare(
            'REPLACE INTO '.$this->table.' (k, v, t, e, ct) VALUES (?, ?, ?, ?, ?)'
        );
        // $this->clearExpiredMaybe();
        $value = $this->serialize($value);
        $token = \md5($value);
        $expiry = $this->expiry($expire);
        $calcTime = $this->lastGetInfo['key'] == $key
            ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
            : 0;
        $stmt->bind_param(
            'ssssi',
            $key,
            $value,
            $token,
            $expiry,
            $calcTime
        );
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        // 1 = insert; 2 = update
        return $affectedRows === 1 || $affectedRows === 2;
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
        $stmt = $this->client->prepare(
            'REPLACE INTO '.$this->table.' (k, v, t, e)
            VALUES '.\implode(', ', \array_fill(0, \count($items), '(?,?,?,?)'))
        );
        $types = \str_repeat('ssss', \count($items));
        // we need to pass by reference
        $middleMan = array();
        foreach ($items as $key => $value) {
            $items[$key] = null;    // free memory
            $value = $this->serialize($value);
            $middleMan[$key] = array(
                'k' => $key,
                'v' => $value,
                't' => \md5($value),
            );
            $binder[] = &$middleMan[$key]['k'];
            $binder[] = &$middleMan[$key]['v'];
            $binder[] = &$middleMan[$key]['t'];
            $binder[] = &$expiry;
        }
        \call_user_func_array(
            array($stmt, 'bind_param'),
            \array_merge(array($types), $binder)
        );
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        /*
            Can't compare with count($items) because affected_rows could be 1 or 2,
            depending on if REPLACE was an INSERT or UPDATE.
        */
        $success = $affectedRows > 0;
        return \array_fill_keys(\array_keys($items), $success);
    }

    /**
     * Expired entries shouldn't keep filling up the database. Additionally,
     * we will want to remove those in order to properly rely on INSERT (for
     * add) and UPDATE (for replace), which assume a column exists or not, not
     * taking the expiration status into consideration.
     * An expired column should simply not exist.
     *
     * @return void
     */
    protected function clearExpired()
    {
        $stmt = $this->client->prepare(
            'DELETE FROM '.$this->table.'
            WHERE e < ?'
        );
        $expiry = \gmdate(self::DATETIME_FORMAT);
        $stmt->bind_param('s', $expiry);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Expired entries shouldn't keep filling up the database.
     * But clearing expired can be expensive
     * So...  this will clear expired some of the time
     *
     * @return void
     */
    protected function clearExpiredMaybe()
    {
        $rand = \rand(0, 99);
        $prob = 10;  // percent
        if ($rand < $prob) {
            $this->clearExpired();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        $this->clearExpired();
        $value = $this->get($key, $token);
        if ($value === false) {
            $success = $this->cas($token, $key, $initial, $expire);
            return $success ? $initial : false;
        }
        if (!\is_numeric($value)) {
            return false;
        }
        $value += $offset;
        $success = $this->cas($token, $key, $value, $expire);
        return $success ? $value : false;
    }

    /**
     * Convert expiry to UTC TIMESTAMP (Y-m-d H:i:s) format
     *
     * @param mixed $expire null: no expiration
     *                      integer: relative/absolute time (0 = no expiration)
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return null|string
     */
    protected function expiry($expire)
    {
        $expiry = parent::expiry($expire);
        if ($expiry === 0) {
            return null;
        }
        return \gmdate(self::DATETIME_FORMAT, $expiry);
    }

    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        $this->client->query(
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

    /**
     * Serialize value for storage
     *
     * @param mixed $value value to serialize
     *
     * @return string|integer
     */
    protected function serialize($value)
    {
        return \is_int($value) || \is_float($value)
            ? $value
            : \serialize($value);
    }

    /**
     * Numbers aren't serialized for storage size purposes.
     *
     * @param mixed $value value to unserialize
     *
     * @return mixed|integer|float
     */
    protected function unserialize($value)
    {
        if (\is_numeric($value)) {
            $int = (int) $value;
            if ((string) $int === $value) {
                return $int;
            }
            $float = (float) $value;
            if ((string) $float === $value) {
                return $float;
            }
            return $value;
        }
        return \unserialize($value);
    }
}
