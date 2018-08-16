<?php

namespace bdk\SimpleCache\Adapters;

use DateInterval;
use DateTime;
use DateTimeZone;
use bdk\SimpleCache\KeyValueStoreInterface;

/**
 *
 */
abstract class Base implements KeyValueStoreInterface
{

    protected $lastGetInfo = array(
        'calcTime'          => null,
        'code'              => 'notExist',  // "hit", "notExist", or "expired"
        'expiredValue'      => null,
        'expiry'            => null,
        // 'expiryOriginal' => null,
        'key'               => '',
        'microtime'         => null,    // time of get.. so we can calc computation time
        'token'             => null,
    );

    /**
     * {@inheritdoc}
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        return $this->doIncrement($key, -$offset, $initial, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys)
    {
        $success = array();
        foreach ($keys as $key) {
            $success[$key] = $this->delete($key);
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo()
    {
        return $this->lastGetInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $values = array();
        $tokens = array();
        foreach ($keys as $key) {
            $token = null;
            $value = $this->get($key, $token);
            if ($token !== null) {
                $values[$key] = $value;
                $tokens[$key] = $token;
            }
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function getSet($key, callable $getter, $expire = 0, $failDelay = 60)
    {
        $return = $this->get($key);
        if ($this->lastGetInfo['code'] === 'hit') {
            return $return;
        }
        $valNew = \call_user_func($getter);
        if ($valNew !== false) {
            $this->set($key, $valNew, $expire);
            return $valNew;
        }
        // getter callable failed... push out expiry and return expired value
        $expiry = $this->expiry($expire);
        if ($this->lastGetInfo == 'expired' && $expiry && $failDelay) {
            $datetime = new DateTime();
            $datetime->setTimestamp($expiry);
            $datetime->modify('+'.$failDelay.' seconds');
            // failure may have taken longer than a success...
            // "reset" microtime such that cas will calc the last calctime
            $this->lastGetInfo['microtime'] = \microtime(true) - $this->lastGetInfo['calctime'] / 1000000;
            $this->cas($this->lastGetInfo, $key, $this->lastGetInfo['expiredValue'], $datetime);
        }
        return $this->lastGetInfo['expiredValue'];
    }

    /**
     * {@inheritdoc}
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        return $this->doIncrement($key, $offset, $initial, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $items, $expire = 0)
    {
        $success = array();
        foreach ($items as $key => $value) {
            $success[$key] = $this->set($key, $value, $expire);
        }
        return $success;
    }
    /**
     * Decode serialized value & metadata
     *
     * @param string $data meta + data string
     *
     * @return array
     */
    protected function decode($data)
    {
        $data = \unserialize($data);
        $data['t'] = \md5(\serialize($data['v']));
        return $data;
    }

    /**
     * Handle decrement/increment
     *
     * @param string  $key     key
     * @param integer $offset  Amount to add
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param mixed   $expire  expiration
     *
     * @return integer|boolean
     *
     * @internal Extenf for implementation specific
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
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
     * Build value, token & expiration time to be stored as cached value
     *
     * @param string $value value to store
     * @param array  $meta  meta information including expire
     *
     * @return string
     */
    protected function encode($value, $meta = array())
    {
        $meta['v'] = $value;
        return \serialize($meta);
    }

    /**
     * Convert expiry to unix timestamp
     *
     * @param mixed $expire null: no expiration (0 is returned)
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration (0 is returned)
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return integer unix timestamp (0 if no expiration)
     */
    protected function expiry($expire)
    {
        if ($expire === 0 || $expire === null) {
            return 0;
        }
        if (\is_numeric($expire)) {
            if ($expire <= 30 * 24 * 60 * 60) {
                // relative time in seconds, <=30 days
                $expire += \time();
            }
            return (int) \round($expire);
        }
        if ($expire instanceof DateTime) {
            // $expire->setTimezone(new DateTimeZone('UTC'));
            return (int) $expire->format('U');
        }
        if ($expire instanceof DateInterval) {
            // convert DateInterval to integer by adding it to a 0 DateTime
            $datetime = new DateTime();
            $datetime->add($expire);
            return (int) $datetime->format('U');
        }
        if (\is_string($expire) && \preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expire)) {
            // ASSUME UTC
            // return $expire;
            $expire = new DateTime($expire, new DateTimeZone('UTC'));
            return (int) $expire->format('U');
        }
    }

    /**
     * Reset lastGetInfo array
     *
     * @param string $key key value
     *
     * @return void
     */
    protected function resetLastGetInfo($key = null)
    {
        $this->lastGetInfo = array(
            'calcTime'          => null,
            'code'              => 'notExist',
            'expiredValue'      => null,
            'expiry'            => null,
            'key'               => $key,
            'microtime'         => \microtime(true),    // time of get.. so we can calc computation time
            'token'             => null,
        );
    }

    /**
     * Convert expiration to TTL
     *
     * @param mixed $expire null: no expiration
     *                      integer: relative/absolute time in seconds
     *                          0 : no expiration
     *                          <= 30days : relative time
     *                          > 30days : absolute time
     *                      string ("YYYY-MM-DD HH:MM:SS") provide in UTC time
     *                      DateTime object
     *                      DateInterval object
     *
     * @return integer TTL in seconds
     */
    protected function ttl($expire)
    {
        if ($expire === 0 || $expire === null) {
            return 0;
        }
        if (\is_numeric($expire)) {
            if ($expire <= 30 * 24 * 60 * 60) {
                // relative time in seconds, <=30 days
                return (int) \round($expire);
            }
            return (int) \round($expire - \time());
        }
        if ($expire instanceof DateTime) {
            // $expire->setTimezone(new DateTimeZone('UTC'));
            return $expire->format('U') - \time();
        }
        if ($expire instanceof DateInterval) {
            // convert DateInterval to integer by adding it to a 0 DateTime
            $datetime = new DateTime();
            $datetime->add($expire);
            return (int) $datetime->format('U') - \time();
        }
        if (\is_string($expire) && \preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expire)) {
            // ASSUME UTC
            // return $expire;
            $expire = new DateTime($expire, new DateTimeZone('UTC'));
            return (int) $expire->format('U') - \time();
        }
    }
}
