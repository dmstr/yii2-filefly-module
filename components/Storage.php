<?php
// file generated with AI assistance: Claude Code - [2026-11-11 20:00:00 UTC]
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2026 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use hrzg\filefly\components\storage\InvalidPathException;
use hrzg\filefly\components\storage\ItemExistsException;
use hrzg\filefly\components\storage\ItemInfo;
use hrzg\filefly\components\storage\ItemNotFoundException;
use hrzg\filefly\components\storage\StorageException;
use hrzg\filefly\components\storage\StorageItem;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemException;
use League\Flysystem\Util;
use League\Flysystem\Util\MimeType;
use yii\base\BaseObject;

/**
 * The module's only access to a filesystem: every read and write of files and directories goes
 * through an instance of this class, and nothing of the underlying library (currently Flysystem
 * 1; its types, array shapes, exception classes or method names) is part of its API.
 *
 * Obtain the instance for the current module with FileManager::storage().
 *
 * ## Paths
 *
 * Every method accepts a path relative to the storage root, with or without leading slash, and
 * normalises it with normalizePath() before anything else: `\` becomes `/`, empty and `.`
 * segments are dropped (so a trailing slash makes no difference), `..` removes the segment
 * before it. `/`, `` and `.` all name the root. A path that leaves the root or contains control
 * characters is rejected with InvalidPathException before any request to the adapter.
 *
 * ## Failures
 *
 * - InvalidPathException (a \LogicException): the path is refused, see above.
 * - ItemNotFoundException: the operation needs an existing source and there is none. The root
 *   never counts as existing (exists('/') is false).
 * - ItemExistsException: a write, copy or move would replace an existing file or directory.
 *   Every writing method refuses to replace anything; source and target stay unchanged. (The
 *   existence check and the write are two requests, not atomic.)
 * - StorageException: any other failure the filesystem library reports (for example a link that
 *   the adapter is configured to refuse).
 * - `false` return value: the adapter performed the operation and reported failure.
 * - Errors outside the library are not translated: the local adapter lets PHP warnings of
 *   rename()/copy()/fopen() and \RuntimeException of SplFileInfo (metadata of a missing file in
 *   fileStat()) pass; with Yii's error handler they arrive as exceptions. Callers must not rely
 *   on their class.
 *
 * All four exception classes carry the message of the library's exception (it is logged by
 * callers) and the library's exception as previous.
 *
 * ## Links (local adapter)
 *
 * With the default `linkHandling` (DISALLOW_LINKS) a symlink as last path segment makes
 * itemInfo(), fileStat() and listContents() throw StorageException; with SKIP_LINKS
 * itemInfo()/fileStat() return `null` for it and listContents() leaves it out. A type `link` is
 * never reported. Links inside a path are followed by the operating system.
 *
 * ## Requests
 *
 * The number of adapter requests per method is documented where it matters for remote adapters
 * (S3): fileStat() and fileStream() make exactly one request each and no existence check.
 *
 * Statements marked "local adapter" were verified against the local adapter only; behaviour of
 * other adapters is whatever their Flysystem 1 adapter does.
 */
class Storage extends BaseObject
{
    /**
     * The filesystem to work on: the yii filesystem component (creocoder\flysystem\Filesystem) or
     * a Flysystem 1 filesystem (League\Flysystem\FilesystemInterface). This construction seam is
     * the only place where the library's types enter the facade.
     *
     * @var object
     */
    public $filesystem;

    /**
     * Mime type for a file name, by its extension only (no request, the file need not exist).
     *
     * Unknown or missing extension: `text/plain` (never an empty value).
     *
     * @param string $fileName file name or path
     *
     * @return string
     */
    public static function mimeTypeByFileName($fileName)
    {
        return MimeType::detectByFilename($fileName);
    }

    /**
     * Mime type registered for a lower-case extension (without dot), `null` if none is.
     *
     * Same table as mimeTypeByFileName(), but unknown extensions give `null` instead of
     * `text/plain`.
     *
     * @param string $extension
     *
     * @return string|null
     */
    public static function mimeTypeByExtension($extension)
    {
        return MimeType::getExtensionToMimeTypeMap()[$extension] ?? null;
    }

    /**
     * The normalised form of a path (see "Paths" in the class doc): relative to the root, no
     * leading or trailing slash, `''` for the root. Makes no request.
     *
     * @param string $path
     *
     * @return string
     * @throws InvalidPathException if the path leaves the root or contains control characters
     */
    public function normalizePath($path)
    {
        try {
            return Util::normalizePath($path);
        } catch (\LogicException $e) {
            throw new InvalidPathException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Whether a file or directory exists at the path. Always `false` for the root.
     *
     * @param string $path
     *
     * @return bool
     * @throws InvalidPathException
     */
    public function exists($path)
    {
        $path = $this->normalizePath($path);
        return $this->call(function () use ($path) {
            return $this->filesystem->has($path);
        });
    }

    /**
     * Type, size and mime type of an existing item: an existence check plus one metadata request.
     *
     * @param string $path
     *
     * @return ItemInfo|null `null` if the adapter reports no metadata for an existing path (local
     *         adapter: a link with SKIP_LINKS)
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function itemInfo($path)
    {
        $path = $this->normalizePath($path);
        $metadata = $this->call(function () use ($path) {
            return $this->filesystem->getMetadata($path);
        });
        return $this->toInfo($metadata);
    }

    /**
     * Type, size and mime type for delivering a file: exactly ONE metadata request to the adapter,
     * no existence check. Used with fileStream() by `stream` and `download`, where every request
     * counts on remote adapters (S3 HEAD requests).
     *
     * A missing item is adapter specific: `null` where the adapter reports nothing, an exception
     * of unspecified class where it fails (local adapter: \RuntimeException of SplFileInfo).
     * Callers treat every exception as "not found".
     *
     * @param string $path
     *
     * @return ItemInfo|null
     * @throws InvalidPathException
     */
    public function fileStat($path)
    {
        $path = $this->normalizePath($path);
        $metadata = $this->call(function () use ($path) {
            return $this->filesystem->getAdapter()->getMetadata($path);
        });
        return $this->toInfo($metadata);
    }

    /**
     * Read stream of a file for delivery: exactly ONE request to the adapter, no existence check
     * (the partner of fileStat()). The caller closes the stream (or hands it to the response).
     *
     * @param string $path
     *
     * @return resource|null `null` if the adapter provides no stream; adapter failures escape as
     *         exceptions of unspecified class
     * @throws InvalidPathException
     */
    public function fileStream($path)
    {
        $path = $this->normalizePath($path);
        $result = $this->call(function () use ($path) {
            return $this->filesystem->getAdapter()->readStream($path);
        });
        if ($result === false || !isset($result['stream'])) {
            return null;
        }
        return $result['stream'];
    }

    /**
     * Read stream of an existing item: an existence check plus one read request.
     *
     * The local adapter opens directories too (the stream then fails on the first read with a
     * PHP notice); callers that must not read directories check the type first.
     *
     * @param string $path
     *
     * @return resource|null `null` if the adapter reports failure
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function readStream($path)
    {
        $path = $this->normalizePath($path);
        $stream = $this->call(function () use ($path) {
            return $this->filesystem->readStream($path);
        });
        return $stream === false ? null : $stream;
    }

    /**
     * Files and directories in a directory, sorted by path case-insensitively (strcasecmp).
     *
     * - The listing of a missing path or of a file is empty (no exception).
     * - Shallow: the direct children. Recursive: every item beneath the directory, directories
     *   included as entries of their own.
     * - `type` is `file`, `dir` or another type of the adapter; links: see the class doc.
     * - `path` of each item is relative to the storage root (not to the listed directory).
     *
     * @param string $path directory, `/` or `` for the root
     * @param bool $recursive
     *
     * @return StorageItem[]
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function listContents($path, $recursive = false)
    {
        $path = $this->normalizePath($path);
        $entries = $this->call(function () use ($path, $recursive) {
            return $this->filesystem->listContents($path, $recursive);
        });
        $items = [];
        foreach ($entries as $entry) {
            $items[] = $this->toItem($entry);
        }
        return $items;
    }

    /**
     * Last modification time of an existing item as unix time: an existence check plus one
     * metadata request.
     *
     * @param string $path
     *
     * @return int|null `null` if the adapter reports none
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function lastModified($path)
    {
        $path = $this->normalizePath($path);
        $timestamp = $this->call(function () use ($path) {
            return $this->filesystem->getTimestamp($path);
        });
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Create a directory, including all missing parent directories.
     *
     * An existing directory at the path counts as success (`true`, nothing changes). An existing
     * file at the path (or as one of the parents) makes it fail with `false` (local adapter).
     * Creating the root succeeds without effect.
     *
     * @param string $path
     *
     * @return bool
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function createDirectory($path)
    {
        $path = $this->normalizePath($path);
        return $this->call(function () use ($path) {
            return $this->filesystem->createDir($path);
        });
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool `false` if the adapter fails, which the local adapter does for a directory at
     *         the path (it is left untouched)
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function delete($path)
    {
        $path = $this->normalizePath($path);
        return $this->call(function () use ($path) {
            return $this->filesystem->delete($path);
        });
    }

    /**
     * Delete a directory with everything beneath it (always recursive; links beneath it are
     * removed, not followed).
     *
     * There is no existence check: a missing path, or a file at the path, gives `false` and
     * nothing changes (local adapter).
     *
     * @param string $path
     *
     * @return bool
     * @throws InvalidPathException for the root (never deleted) and for refused paths
     * @throws StorageException e.g. for an unreadable item beneath the directory (local adapter;
     *         the items deleted up to that point stay deleted)
     */
    public function deleteDirectory($path)
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            throw new InvalidPathException('Root directories can not be deleted.');
        }
        return $this->call(function () use ($path) {
            return $this->filesystem->deleteDir($path);
        });
    }

    /**
     * Move or rename a file or directory within this storage. Missing parent directories of the
     * target are created (local adapter).
     *
     * Order: the source is checked first, then the target.
     *
     * @param string $from
     * @param string $to
     *
     * @return bool `false` if the adapter reports failure
     * @throws ItemNotFoundException if nothing exists at $from
     * @throws ItemExistsException if anything exists at $to, also when $to equals $from; nothing
     *         is moved
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function move($from, $to)
    {
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        return $this->call(function () use ($from, $to) {
            return $this->filesystem->rename($from, $to);
        });
    }

    /**
     * Copy a file within this storage. Missing parent directories of the target are created; the
     * copy gets the current time as modification time (local adapter).
     *
     * Order: the source is checked first, then the target. Copying a directory is not supported:
     * the local adapter fails with a PHP warning (an exception under Yii's error handler).
     *
     * @param string $from
     * @param string $to
     *
     * @return bool `false` if the adapter reports failure
     * @throws ItemNotFoundException if nothing exists at $from
     * @throws ItemExistsException if anything exists at $to; nothing is copied
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function copy($from, $to)
    {
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        return $this->call(function () use ($from, $to) {
            return $this->filesystem->copy($from, $to);
        });
    }

    /**
     * Write a new file from a stream. The stream is rewound first if it is seekable; missing
     * parent directories are created (local adapter). The stream is not closed.
     *
     * The local adapter creates the target file before it copies the content, so a stream that
     * fails while being read (e.g. one opened on a directory) leaves an empty file behind.
     *
     * @param string $path
     * @param resource $stream
     * @param string|false|null $mimeType content type to store with the file where the adapter
     *        supports it (S3), `null` to let the adapter decide; ignored by the local adapter
     *
     * @return bool `false` if the adapter reports failure
     * @throws ItemExistsException if a file or directory exists at the path; nothing is written
     * @throws \InvalidArgumentException if $stream is no stream resource
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function writeStream($path, $stream, $mimeType = null)
    {
        $path = $this->normalizePath($path);
        $config = $mimeType === null ? [] : ['mimetype' => $mimeType];
        return $this->call(function () use ($path, $stream, $config) {
            return $this->filesystem->writeStream($path, $stream, $config);
        });
    }

    /**
     * Runs one call of the library and translates its exceptions into the facade's.
     *
     * @param callable $operation
     *
     * @return mixed
     */
    private function call(callable $operation)
    {
        try {
            return $operation();
        } catch (FileNotFoundException $e) {
            throw new ItemNotFoundException($e->getMessage(), 0, $e);
        } catch (FileExistsException $e) {
            throw new ItemExistsException($e->getMessage(), 0, $e);
        } catch (FilesystemException $e) {
            throw new StorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array|false|null $metadata metadata of the library
     *
     * @return ItemInfo|null
     */
    private function toInfo($metadata)
    {
        if (!$metadata) {
            return null;
        }
        return new ItemInfo(
            isset($metadata['type']) ? $metadata['type'] : null,
            isset($metadata['size']) ? $metadata['size'] : null,
            isset($metadata['mimetype']) ? $metadata['mimetype'] : null
        );
    }

    /**
     * The only place where listing items are built.
     *
     * @param array $entry listing entry of the library; Flysystem 1 has already added the path
     *        info (`dirname`, `basename`, `extension` if any) with Util::pathinfo()
     *
     * @return StorageItem
     */
    private function toItem(array $entry)
    {
        return new StorageItem(
            $entry['path'],
            isset($entry['type']) ? $entry['type'] : null,
            $entry['dirname'],
            $entry['basename'],
            isset($entry['extension']) ? $entry['extension'] : null,
            isset($entry['size']) ? $entry['size'] : null,
            isset($entry['timestamp']) ? $entry['timestamp'] : null
        );
    }
}
