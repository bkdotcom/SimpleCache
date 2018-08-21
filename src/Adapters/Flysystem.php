<?php

namespace bdk\SimpleCache\Adapters;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\FileExistsException;
use League\Flysystem\Filesystem as FlyFilesystem;   // PHP bug 66773 ??
use bdk\SimpleCache\Adapters\Collections\Flysystem as Collection;

/**
 * Flysystem adapter. Data will be written to League\Flysystem\Filesystem.
 *
 * Not all flysystem adapters support locking files.  To guarantee no interference with
 * other processes, we'll create separate lock-files to flag a cache key in use.
 *
 * @see https://flysystem.thephpleague.com/
 */
class Flysystem extends Base
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(FlyFilesystem $filesystem)
    {
        $this->filesystem = $filesystem;
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
            $success = $this->filesystem->write($path, $this->encode($value, $meta));
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
        try {
            $success = $this->filesystem->put($path, $this->encode($value, $meta));
            return $success && $this->unlock($key);
        } catch (FileNotFoundException $e) {
            $this->unlock($key);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $files = $this->filesystem->listContents();
        foreach ($files as $file) {
            try {
                if ($file['type'] === 'dir') {
                    $this->filesystem->deleteDir($file['path']);
                } else {
                    $this->filesystem->delete($file['path']);
                }
            } catch (FileNotFoundException $e) {
                // don't care if we failed to unlink something, might have
                // been deleted by another process in the meantime...
            }
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        if (!$this->lock($key)) {
            return false;
        }
        $path = $this->path($key);
        try {
            $this->filesystem->delete($path);
            $this->unlock($key);
            return true;
        } catch (FileNotFoundException $e) {
            $this->unlock($key);
            return false;
        }
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
        $this->filesystem->createDir($name);
        return new Collection($this->filesystem, $name);
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
            'expire' => $expire,
        );
        try {
            $success = $this->filesystem->update($path, $this->encode($value, $meta));
            return $success && $this->unlock($key);
        } catch (FileNotFoundException $e) {
            $this->unlock($key);
            return false;
        }
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
        $success = $this->filesystem->put($path, $this->encode($value, $meta));
        return $success !== false && $this->unlock($key);
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
            'expire' => $expire,
        );
        try {
            $success = $this->filesystem->update($path, $this->encode($value, $meta));
            return $success && $this->unlock($key);
        } catch (FileNotFoundException $e) {
            $this->unlock($key);
            return false;
        }
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
            $this->filesystem->delete($path);
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
        $path = $key.'.lock';
        for ($i = 0; $i < 25; ++$i) {
            try {
                $this->filesystem->write($path, '');
                return true;
            } catch (FileExistsException $e) {
                \usleep(200);
            }
        }
        return false;
    }

    /**
     * Get filepath for given key
     *
     * @param string $key cache key
     *
     * @return string
     */
    protected function path($key)
    {
        return $key.'.cache';
    }

    /**
     * Fetch stored data from cache file.
     *
     * @param string $key cache key
     *
     * @return boolean|array
     */
    protected function read($key)
    {
        $path = $this->path($key);
        try {
            $data = $this->filesystem->read($path);
        } catch (FileNotFoundException $e) {
            // unlikely given previous 'exists' check, but let's play safe...
            // (outside process may have removed it since)
            return false;
        }
        if ($data === false) {
            // in theory, a file could still be deleted between Flysystem's
            // assertPresent & the time it actually fetched the content
            // extremely unlikely though
            return false;
        }
        return $this->decode($data);
    }

    /**
     * Release the lock for a given key.
     *
     * @param string $key key to unlock
     *
     * @return boolean
     */
    protected function unlock($key)
    {
        $path = $key.'.lock';
        try {
            $this->filesystem->delete($path);
        } catch (FileNotFoundException $e) {
            return false;
        }
        return true;
    }
}
