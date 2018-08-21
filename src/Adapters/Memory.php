<?php

namespace bdk\SimpleCache\Adapters;

use bdk\SimpleCache\Adapters\Collections\Memory as Collection;

/**
 * No-storage cache: all values will be "cached" in memory, in a simple PHP
 * array. Values will only be valid for 1 request: whatever is in memory at the
 * end of the request just dies. Other requests will start from a blank slate.
 *
 * This is mainly useful for testing purposes, where this class can let you test
 * application logic against cache, without having to run a cache server.
 */
class Memory extends Base
{
    /**
     * @var array
     */
    protected $items = array();

    /**
     * @var integer memory limit in bytes
     */
    protected $limit = 0;

    /**
     * @var integer
     */
    protected $size = 0;

    /**
     * Constructor
     *
     * @param integer|string $limit Memory limit in bytes (defaults to 10% of memory_limit)
     */
    public function __construct($limit = null)
    {
        if ($limit === null) {
            $phpLimit = \ini_get('memory_limit');
            if ($phpLimit <= 0) {
                $this->limit = PHP_INT_MAX;
            } else {
                $this->limit = (int) ($this->shorthandToBytes($phpLimit) / 10);
            }
        } else {
            $this->limit = $this->shorthandToBytes($limit);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        if ($this->exists($key)) {
            return false;
        }
        return $this->set($key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        // if (!$this->exists($key)) {
            // return false;
        // }
        $this->get($key);
        if ($this->lastGetInfo['code'] == 'hit' && $token !== $this->lastGetInfo['token']) {
            return false;
        }
        /*
        if ($comparison !== $token) {
            return false;
        }
        */
        return $this->set($key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->items = array();
        $this->size = 0;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $exists = $this->exists($key);
        if ($exists) {
            $this->size -= \strlen($this->items[$key]['v']);
            unset($this->items[$key]);
        }
        return $exists;
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
    public function get($key, &$token = null)
    {
        $this->resetLastGetInfo($key);
        /*
        if (!$this->exists($key)) {
            $token = null;
            return false;
        }
        $value = $this->items[$key][0];
        // use serialized version of stored value as CAS token
        $token = $value;
        return \unserialize($value);
        */
        $token = null;
        $this->resetLastGetInfo($key);
        if (!isset($this->items[$key])) {
            return false;
        }
        $data = $this->items[$key];
        $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
        $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
        $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
            'calcTime' => $data['ct'],
            'code' => 'hit',
            'expiry' => $data['e'],
            // 'expiryOriginal' => $data['eo'],
            'token' => \md5($data['v']),
        ));
        if ($isExpired) {
            $this->lastGetInfo['code'] = 'expired';
            $this->lastGetInfo['expiredValue'] = \unserialize($data['v']);
            return false;
        }
        $token = $this->lastGetInfo['token'];
        return \unserialize($data['v']);

    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        return new Collection($this, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, array &$tokens = null)
    {
        $items = array();
        $tokens = array();
        foreach ($keys as $key) {
            if (!$this->exists($key)) {
                // omit missing keys from return array
                continue;
            }
            $items[$key] = $this->get($key, $token);
            $tokens[$key] = $token;
        }
        return $items;
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        if (!$this->exists($key)) {
            return false;
        }
        return $this->set($key, $value, $expire);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        $expire = $this->expiry($expire);
        if ($expire !== 0 && $expire < \time()) {
            // setting an expired value??
            // just delete it now and be done with it
            return !isset($this->items[$key]) || $this->delete($key);
        }
        $this->size -= isset($this->items[$key])
            ? \strlen($this->items[$key]['v'])
            : 0;
        $this->items[$key] = array(
            'v' => \serialize($value),
            'e' => $expire,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null,
        );
        $this->size += \strlen($this->items[$key]['v']);
        $this->lru($key);
        $this->evict();
        return true;
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
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        $value = $this->get($key, $token);
        return $this->cas($token, $key, $value, $expire);
    }

    /*
        Protected/internal
    */

    /**
     * Checks if a value exists in cache and is not yet expired.
     *
     * @param string $key key to check
     *
     * @return boolean
     */
    protected function exists($key)
    {
        if (!\array_key_exists($key, $this->items)) {
            // key not in cache
            return false;
        }
        $expire = $this->items[$key]['e'];
        if ($expire !== 0 && $expire < \time()) {
            // not permanent & expired
            $this->size -= \strlen($this->items[$key]['v']);
            unset($this->items[$key]);
            return false;
        }
        $this->lru($key);
        return true;
    }

    /**
     * Shared between increment/decrement: both have mostly the same logic
     * (decrement just increments a negative value), but need their validation
     * split up (increment won't accept negative values).
     *
     * @param string  $key     key
     * @param integer $offset  Amount to add
     * @param integer $initial Initial value (if item doesn't yet exist)
     * @param integer $expire
     *
     * @return integer|boolean
     */
    protected function doIncrement($key, $offset, $initial, $expire)
    {
        if (!$this->exists($key)) {
            $this->set($key, $initial, $expire);
            return $initial;
        }
        $value = $this->get($key);
        if (!\is_numeric($value)) {
            return false;
        }
        $value += $offset;
        $this->set($key, $value, $expire);
        return $value;
    }

    /**
     * Remove least recently used cache values until total store within limit
     *
     * @return void
     */
    protected function evict()
    {
        while ($this->size > $this->limit && !empty($this->items)) {
            $item = \array_shift($this->items);
            $this->size -= \strlen($item['v']);
        }
    }

    /**
     * Move key to last position
     *
     * @param sttring $key key
     *
     * @return void
     */
    protected function lru($key)
    {
        $data = $this->items[$key];
        unset($this->items[$key]);
        $this->items[$key] = $data;
    }

    /**
     * Understands shorthand byte values (as used in e.g. memory_limit ini
     * setting) and converts them into bytes.
     *
     * @param string|integer $shorthand Amount of bytes (int) or shorthand value (e.g. 512M)
     *
     * @see http://php.net/manual/en/faq.using.php#faq.using.shorthandbytes
     *
     * @return integer
     */
    protected function shorthandToBytes($shorthand)
    {
        if (\is_numeric($shorthand)) {
            // make sure that when float(1.234E17) is passed in, it doesn't get
            // cast to string('1.234E17'), then to int(1)
            return $shorthand;
        }
        $units = array('B' => 1024, 'M' => \pow(1024, 2), 'G' => \pow(1024, 3));
        return (int) \preg_replace_callback('/^([0-9]+)('.\implode(\array_keys($units), '|').')$/', function ($match) use ($units) {
            return $match[1] * $units[$match[2]];
        }, $shorthand);
    }
}
