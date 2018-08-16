<?php

namespace bdk\SimpleCache\Adapters;

use PDO;
use bdk\SimpleCache\Adapters\Collections\Pdo as Collection;

/**
 * SQL adapter. Basically just a wrapper over \PDO, but in an exchangeable
 * (KeyValueStore) interface.
 *
 * This abstract class should be a "fits all DB engines" normalization. It's up
 * to extending classes to optimize for that specific engine.
 */
abstract class PdoBase extends Base
{

    const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @var PDO
     */
    protected $client;

    /**
     * Constructor
     *
     * @param PDO    $client PDO client
     * @param string $table  cache table name
     */
    public function __construct(PDO $client, $table = 'cache')
    {
        $this->client = $client;
        $this->table = $table;
        // don't throw exceptions - it's ok to fail, as long as the return value reflects it
        // $this->client->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        // make sure the database exists (or just "fail" silently)
        $this->init();
        // now's a great time to clean up all expired items
        $this->clearExpired();
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        $token = \md5($value);
        $this->clearExpired();
        $statement = $this->client->prepare(
            'INSERT INTO '.$this->table.' (k, v, t, e)
                VALUES (:key, :value, :token, :expiry)'
        );
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':token' => $token,
            ':expiry' => $this->expiry($expire),
        ));
        return $statement->rowCount() === 1;
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $expiry = $this->expiry($expire);
        $value = $this->serialize($value);
        $tokenNew = \md5($value);
        $statement = $this->client->prepare(
            'UPDATE '.$this->table.'
            SET v = :value, t = :tokenNew, e = :expiry, ct = :calcTime
            WHERE k = :key AND t = :token'
        );
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':token' => $token,
            ':tokenNew' => $tokenNew,
            ':expiry' => $expiry,
            // ':eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : $expire,
            ':calcTime' => $this->lastGetInfo['key'] == $key
                ? \round((\microtime(true) - $this->lastGetInfo['microtime']) * 1000000)
                : null
        ));
        if ($statement->rowCount() === 1) {
            return true;
        }
        /*
            rowCount() came back 0 -> confirm whether updated
                if the value we've just cas'ed was the same as the replacement, as
                well as the same expiration time, rowCount will have been 0
                even though the operation was a success
        */
        $statement = $this->client->prepare(
            'SELECT * FROM '.$this->table.'
            WHERE k = :key'
        );
        $statement->execute(array(
            ':key' => $key,
        ));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            // key not in db -> add
            return $this->add($key, $this->unserialize($value), $expire);
        } elseif ($row['v'] == $value && $row['t'] == $tokenNew && $row['e'] == $expiry) {
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
        // TRUNCATE doesn't work on SQLite - DELETE works for all
        return $this->client->exec('DELETE FROM '.$this->table) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $statement = $this->client->prepare(
            'DELETE FROM '.$this->table.' WHERE k = :key'
        );
        $statement->execute(array(':key' => $key));
        return $statement->rowCount() === 1;
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
        $quoted = array();
        foreach ($keys as $key) {
            $quoted[] = $this->client->quote($key);
        }
        $statement = $this->client->query(
            'DELETE FROM '.$this->table.' WHERE k IN ('.\implode(',', $quoted).')'
        );
        $success = $statement->rowCount() !== 0;
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
        $statement = $this->client->prepare(
            'SELECT v, t, e, ct
            FROM '.$this->table.'
            WHERE k = :key' //  AND (e IS NULL OR e > :expire)
        );
        $statement->execute(array(
            ':key' => $key,
            // ':expire' => gmdate(self::DATETIME_FORMAT), // right now!
        ));
        $data = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
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
        // escape input, can't bind multiple params for IN()
        $quoted = array();
        foreach ($keys as $key) {
            $quoted[] = $this->client->quote($key);
        }
        $statement = $this->client->prepare(
            'SELECT k, v, t
            FROM '.$this->table.'
            WHERE
                k IN ('.\implode(',', $quoted).') AND
                (e IS NULL OR e > :expiry)'
        );
        $statement->execute(array(':expiry' => \gmdate(self::DATETIME_FORMAT)));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $return = array();
        $tokens = \array_column($rows, 't', 'k');
        foreach ($rows as $row) {
            $return[$row['k']] = $this->unserialize($row['v']);
        }
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        $value = $this->serialize($value);
        $expire = $this->expiry($expire);
        $this->clearExpired();
        $statement = $this->client->prepare(
            'UPDATE '.$this->table.'
            SET v = :value, e = :expire
            WHERE k = :key'
        );
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':expire' => $expire,
        ));
        if ($statement->rowCount() === 1) {
            return true;
        }
        // if the value we've just replaced was the same as the replacement, as
        // well as the same expiration time, rowCount will have been 0, but the
        // operation was still a success
        $statement = $this->client->prepare(
            'SELECT e
            FROM '.$this->table.'
            WHERE k = :key AND v = :value'
        );
        $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
        ));
        return $statement->fetchColumn(0) === $expire;
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        // PostgreSQL doesn't have a decent UPSERT (like REPLACE or even INSERT
        // ... ON DUPLICATE KEY UPDATE ...); here's a "works for all" downgrade
        $isSuccess = $this->add($key, $value, $expire);
        if ($isSuccess) {
            return true;
        }
        $statement = $this->client->prepare(
            'UPDATE '.$this->table.'
            SET v = :value, t = :token, e = :expiry, ct = :calcTime
            WHERE k = :key'
        );
        $value = $this->serialize($value);
        return $statement->execute(array(
            ':key' => $key,
            ':value' => $value,
            ':token' => \md5($value),
            ':expiry' => $this->expiry($expire),
            ':calcTime' => $this->lastGetInfo['key'] == $key
                ? \round((\microtime(true) - $this->lastGetInfo['microtime']) * 1000000)
                : 0,
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        $success = array();
        // PostgreSQL's lack of a decent UPSERT is even worse for multiple
        // values - we can only do them one at a time...
        foreach ($items as $key => $value) {
            $success[$key] = $this->set($key, $value, $expire);
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function touch($key, $expire)
    {
        $expire = $this->expiry($expire);
        $this->clearExpired();
        $statement = $this->client->prepare(
            'UPDATE '.$this->table.'
            SET e = :expire
            WHERE k = :key'
        );
        $statement->execute(array(
            ':key' => $key,
            ':expire' => $expire,
        ));
        return $statement->rowCount() === 1;
    }
    */

    /*
        Protected/internal
    */

    /**
     * Create the database/indices if it does not already exist.
     */
    abstract protected function init();

    /**
     * {@inheritdoc}
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        /*
         * I used to have all this logic in a huge & ugly query, but getting
         * that right on multiple SQL engines proved challenging (SQLite doesn't
         * do INSERT ... ON DUPLICATE KEY UPDATE ..., for example)
         * I'll just stuff it in a transaction & leverage existing methods.
         */
        /*
        $this->client->beginTransaction();
        // $this->clearExpired();
        $value = $this->get($key, $token);
        if ($value === false) {
            $return = $this->add($key, $initial, $expire);
            if ($return) {
                $this->client->commit();
                return $initial;
            }
        } elseif (\is_numeric($value)) {
            $value += $offset;
            $return = $this->replace($key, $value, $expire);
            if ($return) {
                $this->client->commit();
                return (int) $value;
            }
        }
        $this->client->rollBack();
        return false;
        */
        $this->clearExpired();
        $value = $this->get($key, $token);
        if ($value === false) {
            $success = $this->cas($token, $key, $initial, $expire);
            return $success ? $initial : false;
            /*
            $return = $this->add($key, $initial, $expire);
            if ($return) {
                $this->client->commit();
                return $initial;
            }
            */
        }
        if (!\is_numeric($value)) {
            return false;
        }
        $value += $offset;
        $success = $this->cas($token, $key, $value, $expire);
        return $success ? $value : false;
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
        $statement = $this->client->prepare(
            'DELETE FROM '.$this->table.'
            WHERE e < :expiry'
        );
        $statement->execute(array(':expiry' => \gmdate(self::DATETIME_FORMAT)));
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
