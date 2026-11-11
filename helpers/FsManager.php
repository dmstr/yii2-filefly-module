<?php

namespace hrzg\filefly\helpers;

use hrzg\filefly\components\Storage;
use hrzg\filefly\components\storage\StorageItem;

/**
 * Storages of several filesystems, addressed by URIs of the form `<scheme>://<path>`, for the
 * console command `fs`.
 *
 * The schemes are the keys of the module's `filesystemComponents`; mount() makes one available.
 * Every operation on a URI behaves like the Flysystem 1 MountManager this class extended before:
 *
 * - a URI without `://` (or starting with it) and an unmounted scheme are refused with
 *   \InvalidArgumentException before any filesystem request
 * - copyFile() always streams: it opens the source for reading (Storage::openFile(), missing source
 *   → ItemNotFoundException), then writes a new file at the target (Storage::writeFile()), also
 *   within one filesystem. So the copy gets the current time as modification time, an existing
 *   target is refused with ItemExistsException after the source was opened, and a directory as
 *   source leaves an empty file at the target before the write fails on reading the stream
 * - replaceFile() streams like copyFile(), but writes with Storage::replaceFile(): an existing FILE
 *   at the target is replaced, a directory there is refused with ItemExistsException. Only sync()
 *   uses it
 * - move() is Storage::move() within one filesystem; across filesystems it is copyFile() followed by
 *   deleteFile() of the source, the latter only if the copy reported success
 *
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class FsManager
{
    private $_module;

    /**
     * @var Storage[] by scheme
     */
    private $_storages = [];

    public function setModule($module)
    {
        $this->_module = $module;
    }
    public function getModule()
    {
        return $this->_module;
    }

    /**
     * Make the filesystem of a scheme available (again: a mounted scheme gets a fresh storage).
     *
     * An unknown scheme surfaces as PHP warning about the undefined array key (an exception
     * under the error handler), as before.
     *
     * @param string $fs scheme, a key of the module's `filesystemComponents`
     */
    public function mount($fs)
    {
        $component = $this->getModule()->filesystemComponents[$fs];
        $this->_storages[$fs] = new Storage(['filesystem' => \Yii::$app->{$component}]);
    }

    /**
     * Copy the files of $src to $dest where the target is missing, and replace target files that
     * are older than their source (modification time); directories are skipped (and created
     * implicitly for the files beneath them). The target URI of an item is
     * `$dest . <path of the item relative to the root of its filesystem>`. A directory at the target
     * of a newer source file is not replaced: replaceFile() throws and the sync aborts.
     *
     * @param string $src
     * @param string $dest
     * @param bool $recursive
     */
    public function sync($src, $dest, $recursive)
    {
        $manager = $this;

        $this->mount($this->parseScheme($src));
        $this->mount($this->parseScheme($dest));

        $srcScheme = $manager->schemeOf($src);
        $contents = $manager->listItems($src, $recursive);
        \Yii::trace($this->traceable($contents, $srcScheme), __METHOD__);
        foreach ($contents as $entry) {
            $update = false;
            $replace = false;
            $srcUrl = $srcScheme.'://'.$entry->getPath();
            $destUrl = $dest.$entry->getPath();

            if ($entry->getType() == 'dir') {
                \Yii::trace("Skipped directory '{$entry->getPath()}'");
                continue;
            }

            if (!$manager->exists($destUrl)) {
                $update = true;
            } elseif ($manager->lastModified($srcUrl) > $manager->lastModified($dest.$entry->getPath())) {
                $update = true;
                $replace = true;
            }

            if ($update) {
                if ($replace) {
                    // replaces an older target file; a directory at the target is refused (throws)
                    $manager->replaceFile($srcUrl, $destUrl);
                } else {
                    $manager->copyFile($srcUrl, $destUrl);
                }
                \Yii::trace("copied file $srcUrl to $destUrl");
                #$this->stdout($srcUrl."\n");
            } else {
                \Yii::trace("skipped file $srcUrl");
            }
        }
        \Yii::trace("sync completed");
    }

    /**
     * The scheme of a URI, `a` for `a://x`
     *
     * @param string $uri
     *
     * @return string
     * @throws \InvalidArgumentException if the URI has no scheme
     */
    public function schemeOf($uri)
    {
        return $this->splitUri($uri)[0];
    }

    /**
     * Storage::listItems() on the filesystem of the URI
     *
     * @param string $uri
     * @param bool $recursive
     *
     * @return StorageItem[] paths relative to the root of that filesystem
     */
    public function listItems($uri, $recursive = false)
    {
        list($storage, $path) = $this->resolve($uri);
        return $storage->listItems($path, $recursive);
    }

    /**
     * Storage::exists() on the filesystem of the URI
     *
     * @param string $uri
     *
     * @return bool
     */
    public function exists($uri)
    {
        list($storage, $path) = $this->resolve($uri);
        return $storage->exists($path);
    }

    /**
     * Storage::lastModified() on the filesystem of the URI
     *
     * @param string $uri
     *
     * @return int|null
     */
    public function lastModified($uri)
    {
        list($storage, $path) = $this->resolve($uri);
        return $storage->lastModified($path);
    }

    /**
     * Storage::createDirectory() on the filesystem of the URI
     *
     * @param string $uri
     *
     * @return bool
     */
    public function createDirectory($uri)
    {
        list($storage, $path) = $this->resolve($uri);
        return $storage->createDirectory($path);
    }

    /**
     * Storage::deleteFile() on the filesystem of the URI
     *
     * @param string $uri
     *
     * @return bool
     */
    public function deleteFile($uri)
    {
        list($storage, $path) = $this->resolve($uri);
        return $storage->deleteFile($path);
    }

    /**
     * Storage::deleteDirectory() on the filesystem of the URI
     *
     * @param string $uri
     *
     * @return bool
     */
    public function deleteDirectory($uri)
    {
        list($storage, $path) = $this->resolve($uri);
        return $storage->deleteDirectory($path);
    }

    /**
     * Copy a file by streaming it from one URI to another, also within one filesystem (see the
     * class doc for the order of effects).
     *
     * @param string $from
     * @param string $to
     *
     * @return bool `false` if the source could not be opened or the write reported failure
     */
    public function copyFile($from, $to)
    {
        return $this->streamFile($from, $to, false);
    }

    /**
     * Like copyFile(), but an existing FILE at the target is replaced (Storage::replaceFile()); a
     * directory at the target is refused with ItemExistsException after the source was opened.
     *
     * @param string $from
     * @param string $to
     *
     * @return bool `false` if the source could not be opened or the write reported failure
     */
    public function replaceFile($from, $to)
    {
        return $this->streamFile($from, $to, true);
    }

    /**
     * Move a file or directory: Storage::move() within one filesystem, copyFile() plus deleteFile() of
     * the source across filesystems.
     *
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    public function move($from, $to)
    {
        list($schemeFrom, $pathFrom) = $this->splitUri($from);
        list($schemeTo, $pathTo) = $this->splitUri($to);

        if ($schemeFrom === $schemeTo) {
            return $this->storage($schemeFrom)->move($pathFrom, $pathTo);
        }

        $copied = $this->copyFile($from, $to);

        if ($copied) {
            return $this->deleteFile($from);
        }

        return false;
    }

    /**
     * Open the source for reading, then write it to the target: Storage::writeFile(), or with
     * $replace Storage::replaceFile().
     *
     * @param string $from
     * @param string $to
     * @param bool $replace
     *
     * @return bool
     */
    private function streamFile($from, $to, $replace)
    {
        list($fromStorage, $fromPath) = $this->resolve($from);

        $buffer = $fromStorage->openFile($fromPath);

        if ($buffer === null) {
            return false;
        }

        list($toStorage, $toPath) = $this->resolve($to);

        if ($replace) {
            $result = $toStorage->replaceFile($toPath, $buffer);
        } else {
            $result = $toStorage->writeFile($toPath, $buffer);
        }

        if (is_resource($buffer)) {
            fclose($buffer);
        }

        return $result;
    }

    private function parseScheme($uri = '/')
    {
        $parts = explode(':', $uri);
        return $parts[0];
    }

    /**
     * @param string $uri
     *
     * @return array [Storage, path]
     */
    private function resolve($uri)
    {
        list($scheme, $path) = $this->splitUri($uri);
        return [$this->storage($scheme), $path];
    }

    /**
     * @param string $uri
     *
     * @return string[] [scheme, path]
     * @throws \InvalidArgumentException if the URI has no scheme
     */
    private function splitUri($uri)
    {
        if (strpos($uri, '://') < 1) {
            throw new \InvalidArgumentException('No prefix detected in path: ' . $uri);
        }

        return explode('://', $uri, 2);
    }

    /**
     * @param string $scheme
     *
     * @return Storage
     * @throws \InvalidArgumentException if the scheme is not mounted
     */
    private function storage($scheme)
    {
        if (!isset($this->_storages[$scheme])) {
            throw new \InvalidArgumentException('No filesystem mounted with prefix ' . $scheme);
        }

        return $this->_storages[$scheme];
    }

    /**
     * The listing as arrays with the scheme as `filesystem`, as the trace log showed it before
     *
     * @param StorageItem[] $contents
     * @param string $scheme
     *
     * @return array[]
     */
    private function traceable(array $contents, $scheme)
    {
        $entries = [];
        foreach ($contents as $item) {
            $entries[] = $item->toArray() + ['filesystem' => $scheme];
        }
        return $entries;
    }
}
