<?php

namespace bdk\SimpleCache\Adapters;

/**
 * Filesystem adapter.
 *
 * Not all filesystems support locking files.  To guarantee no interference with
 * other processes, we'll create separate lock-files to flag a cache key in use.
 */
class Filesystem extends Base
{
    /**
     * @var string $directory base directory
     */
    protected $directory;
    protected $isCollection = false;

    /**
     * Constructor
     *
     * @param string  $directory    base directory
     * @param boolean $isCollection (false) whether this is a collection
     */
    public function __construct($directory, $isCollection = false)
    {
        $directory = \rtrim($directory, '/\\');
        $this->directory = $directory;
        $this->isCollection = $isCollection;
        if (!\file_exists($directory)) {
            \mkdir($directory, 0777, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        if (!$this->lock($key)) {
            return false;
        }
        $expire = $this->expiry($expire);
        $isExisting = $this->exists($key);
        if ($expire !== 0 && $expire < \time()) {
            // adding an expired value??
            // just delete it now and be done with it
            $this->unlock($key);
            return !$isExisting || $this->delete($key);
        }
        if ($isExisting) {
            $this->unlock($key);
            return false;
        }
        $path = $this->path($key);
        $meta = array(
            'e' => $expire,
            // 'eo' => $expire,
            'ct' => null,
        );
        try {
            $success = \file_put_contents($path, $this->encode($value, $meta)) !== false;
            return $success && $this->unlock($key);
        } catch (FileExistsException $e) {
            $this->unlock($key);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        if (!$this->lock($key)) {
            return false;
        }
        $this->get($key);
        if ($this->lastGetInfo['code'] == 'hit' && $token !== $this->lastGetInfo['token']) {
            $this->unlock($key);
            return false;
        }
        $expire = $this->expiry($expire);
        if ($expire !== 0 && $expire < \time()) {
            // setting an expired value??
            // just delete it now and be done with it
            $this->unlock($key);
            return $this->lastGetInfo['code'] != 'hit' || $this->delete($key);
        }
        $path = $this->path($key);
        $meta = array(
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['expiryOriginal'],
            'ct' => (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000,
        );
        $success = \file_put_contents($path, $this->encode($value, $meta));
        return $success && $this->unlock($key);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $directoryQueue = array(
            $this->directory,
        );
        $delQueue = array();    // directories to remove once empty
        while ($directoryQueue) {
            $directory = \array_shift($directoryQueue);
            if ($this->isCollection || $directory !== $this->directory) {
                $delQueue[] = $directory;
            }
            foreach (\glob($directory.'/*') as $file) {
                if (\is_dir($file)) {
                    $directoryQueue[] = $file;
                } else {
                    \unlink($file);
                }
            }
        }
        while ($delQueue) {
            $dir = \array_pop($delQueue);
            if (\is_dir($dir)) {
                \rmdir($dir);
            }
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $path = $this->path($key);
        if (!\file_exists($path)) {
            return false;
        }
        if (!$this->lock($key)) {
            return false;
        }
        $success = \unlink($path);
        return $success && $this->unlock($key);
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $token = null;
        $data = $this->read($key);
        $this->resetLastGetInfo($key);
        if ($data === false) {
            return false;
        }
        $rand = \mt_rand() / \mt_getrandmax();    // random float between 0 and 1 inclusive
        $isExpired = $data['e'] && $data['e'] < \microtime(true) - $data['ct']/1000000 * \log($rand);
        $this->lastGetInfo = \array_merge($this->lastGetInfo, array(
            'calcTime' => $data['ct'],
            'code' => 'hit',
            'expiry' => $data['e'],
            // 'expiryOriginal' => $data['eo'],
            'token' => $data['t'],
        ));
        if ($isExpired) {
            $this->lastGetInfo['code'] = 'expired';
            $this->lastGetInfo['expiredValue'] = $data['v'];
            return false;
        }
        $token = $data['t'];
        return $data['v'];
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection($name)
    {
        return new static($this->directory.DIRECTORY_SEPARATOR.$name, true);
    }

    /**
     * {@inheritdoc}
     */
    /*
    public function replace($key, $value, $expire = 0)
    {
        if (!$this->lock($key)) {
            return false;
        }
        if (!$this->exists($key)) {
            $this->unlock($key);
            return false;
        }
        $path = $this->path($key);
        $meta = array(
            'e' => $expire,
        );
        $success = \file_put_contents($path, $this->encode($value, $meta)) !== false;
        return $success && $this->unlock($key);
    }
    */

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        // we don't really need a lock for this operation, but we need to make
        // sure it's not locked by another operation, which we could overwrite
        if (!$this->lock($key)) {
            return false;
        }
        $expire = $this->expiry($expire);
        if ($expire !== 0 && $expire < \time()) {
            $this->unlock($key);
            // setting an expired value??
            // just delete it now and be done with it
            return !$this->exists($key) || $this->delete($key);
        }
        $path = $this->path($key);
        $meta = array(
            'e' => $expire,
            // 'eo' => $this->lastGetInfo['key'] == $key
                // ? $this->lastGetInfo['expiryOriginal']
                // : null,
            'ct' => $this->lastGetInfo['key'] == $key
                ? (\microtime(true) - $this->lastGetInfo['microtime']) * 1000000
                : null,
        );
        $success = \file_put_contents($path, $this->encode($value, $meta)) !== false;
        return $success && $this->unlock($key);
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        if (!$this->lock($key)) {
            return false;
        }
        $value = $this->get($key);
        if ($value === false) {
            $this->unlock($key);
            return false;
        }
        $path = $this->path($key);
        $meta = array(
            'e' => $expire,
        );
        $success = \file_put_contents($path, $this->encode($value, $meta));
        return $success !== false && $this->unlock($key);
    }

    /*
        Protected/internal
    */

    /**
     * Check if exists and not expired
     *
     * @param string $key key to check
     *
     * @return boolean
     */
    protected function exists($key)
    {
        $data = $this->read($key);
        if ($data === false) {
            return false;
        }
        $expire = $data['e'];
        if ($expire !== 0 && $expire < \time()) {
            // expired, don't keep it around
            $path = $this->path($key);
            \unlink($path);
            return false;
        }
        return true;
    }

    /**
     * Obtain a lock for a given key.
     * It'll try to get a lock for a couple of times, but ultimately give up if
     * no lock can be obtained in a reasonable time.
     *
     * @param string $key key to lock
     *
     * @return boolean
     */
    protected function lock($key)
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$key.'.lock';
        for ($i = 0; $i < 25; ++$i) {
            if (\file_exists($path)) {
                \clearstatcache(true, $path);
                \usleep(200);
                continue;
            }
            $success = \file_put_contents($path, '') !== false;
            if (!$success) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * For given key, return filepath
     *
     * @param string $key key
     *
     * @return string
     */
    protected function path($key)
    {
        return $this->directory.DIRECTORY_SEPARATOR.$key.'.cache';
    }

    /**
     * Fetch stored data from cache file.
     *
     * On success, returned array contains
     *     v => value
     *     e => expiry timestamp
     *     ct => calculation/computation time (microseconds)
     *     t => token
     *
     * @param string $key key
     *
     * @return boolean|array
     */
    protected function read($key)
    {
        $path = $this->path($key);
        if (\file_exists($path)) {
            $data = \file_get_contents($path);
            return $data
                ? $this->decode($data)
                : false;
        }
        return false;
    }

    /**
     * Release the lock for a given key.
     *
     * @param string $key key
     *
     * @return boolean
     */
    protected function unlock($key)
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$key.'.lock';
        if (\file_exists($path)) {
            return \unlink($path);
        } else {
            return false;
        }
    }
}
