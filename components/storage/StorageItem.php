<?php
// file generated with AI assistance: Claude Code - [2026-11-11 20:00:00 UTC]
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2026 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components\storage;

/**
 * One entry of a listing (Storage::listItems()), in the module's fixed shape.
 *
 * Built only by Storage::listItems(). The name parts are derived from `path` alone:
 *
 * - `path`: relative to the storage root, no leading or trailing slash (`d/a.txt`)
 * - `dirname`: `path` without its last segment, `''` for items directly in the root (`d`)
 * - `basename`: the last segment of `path` (`a.txt`)
 * - `extension`: what follows the last dot of `basename` (`txt`; `''` for `a.`), `null` if
 *   `basename` contains no dot (also for directories named like `x.d`, which get `d`)
 * - `filename` (only in toArray()): `basename` without the last dot and extension (`a`)
 *
 * PHP's pathinfo() on `basename` defines `extension` and `filename` (so `.htaccess` has the
 * extension `htaccess` and the filename `''`).
 *
 * Values reported by the adapter, `null` when it does not report them:
 *
 * - `type`: `file`, `dir`, or another type of the adapter (see Storage::listItems())
 * - `size`: bytes; the local adapter reports it for files only
 * - `timestamp`: last modification as unix time; the local adapter reports it for every item,
 *   other adapters may omit it (emulated directories)
 */
class StorageItem
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var string|null
     */
    private $type;

    /**
     * @var string
     */
    private $dirname;

    /**
     * @var string
     */
    private $basename;

    /**
     * @var string|null
     */
    private $extension;

    /**
     * @var int|null
     */
    private $size;

    /**
     * @var int|null
     */
    private $timestamp;

    /**
     * @param string $path
     * @param string|null $type
     * @param string $dirname
     * @param string $basename
     * @param string|null $extension
     * @param int|null $size
     * @param int|null $timestamp
     */
    public function __construct($path, $type, $dirname, $basename, $extension, $size, $timestamp)
    {
        $this->path = $path;
        $this->type = $type;
        $this->dirname = $dirname;
        $this->basename = $basename;
        $this->extension = $extension;
        $this->size = $size;
        $this->timestamp = $timestamp;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string|null
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getDirname()
    {
        return $this->dirname;
    }

    /**
     * @return string
     */
    public function getBasename()
    {
        return $this->basename;
    }

    /**
     * @return string|null
     */
    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * @return int|null
     */
    public function getFileSize()
    {
        return $this->size;
    }

    /**
     * @return int|null
     */
    public function getLastModified()
    {
        return $this->timestamp;
    }

    /**
     * The item as the array the module hands to its `urlCallback`, `thumbnailCallback` and
     * `previewCallback` (Module properties) for listed items.
     *
     * Keys in this order, a key whose value is `null` is left out: `type`, `path`, `timestamp`,
     * `size`, `dirname`, `basename`, `extension`, `filename`. For the local adapter this is
     * exactly the listing entry of Flysystem 1 that the callbacks received before.
     *
     * @return array
     */
    public function toArray()
    {
        $array = [
            'type' => $this->type,
            'path' => $this->path,
            'timestamp' => $this->timestamp,
            'size' => $this->size,
            'dirname' => $this->dirname,
            'basename' => $this->basename,
            'extension' => $this->extension,
            'filename' => pathinfo($this->basename, PATHINFO_FILENAME),
        ];
        return array_filter($array, static function ($value) {
            return $value !== null;
        });
    }
}
