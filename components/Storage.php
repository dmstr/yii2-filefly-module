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

use creocoder\flysystem\Filesystem as FilesystemComponent;
use creocoder\flysystem\LocalFilesystem;
use hrzg\filefly\components\storage\InvalidPathException;
use hrzg\filefly\components\storage\ItemExistsException;
use hrzg\filefly\components\storage\ItemInfo;
use hrzg\filefly\components\storage\ItemNotFoundException;
use hrzg\filefly\components\storage\StorageException;
use hrzg\filefly\components\storage\StorageItem;
use League\Flysystem\Config;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InvalidStreamProvided;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\StorageAttributes;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\WhitespacePathNormalizer;
use League\MimeTypeDetection\GeneratedExtensionToMimeTypeMap;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;

/**
 * The module's only access to a filesystem: every read and write of files and directories goes
 * through an instance of this class, and nothing of the underlying library (Flysystem 3; its
 * types, attribute objects, exception classes or method names) is part of its API.
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
 *   Every writing method refuses to replace anything; source and target stay unchanged.
 *   Flysystem 3 replaces existing files silently on every adapter, so the facade checks the
 *   target itself before it writes (the check and the write are two requests, not atomic). The
 *   root counts as an existing target. The one exception is replaceFile(), the only operation
 *   allowed to replace an existing FILE; it still refuses a directory.
 * - StorageException: any other failure the filesystem library reports (for example a link that
 *   the adapter is configured to refuse, a listing or a read that fails).
 * - `false` return value: the adapter performed the operation and reported failure. Flysystem 3
 *   reports every failure as exception; for writeFile()/replaceFile(), createDirectory() and
 *   deleteFile() the failure of the operation itself (UnableToWriteFile, UnableToCreateDirectory,
 *   UnableToDeleteFile) becomes `false`, as the local adapter of Flysystem 1 returned `false`
 *   there. A failing move() or copyFile() throws StorageException: Flysystem 1's local adapter
 *   raised the PHP warning of rename()/copy() there, which became an exception as well.
 * - \InvalidArgumentException: a write got no stream resource.
 *
 * The exception classes carry the message of the library's exception (callers log it) and the
 * library's exception as previous. Messages of the local adapter can contain absolute disk
 * paths: callers must not show them to end users.
 *
 * ## Links (local adapter)
 *
 * Flysystem 3 follows links on direct access and checks them only in listings, so the facade
 * checks the last path segment itself (one `lstat()` on the local disk, no adapter request),
 * with the `linkHandling` of the creocoder LocalFilesystem component:
 *
 * - DISALLOW_LINKS (the default): a symlink as last path segment of an existing item makes
 *   itemInfo(), fileStat(), lastModified() and replaceFile() throw StorageException, and any
 *   symlink in the listed tree makes listItems() throw StorageException
 * - SKIP_LINKS: itemInfo()/fileStat()/lastModified() return `null` for such a link,
 *   replaceFile() refuses it with ItemExistsException, listItems() leaves links out
 * - deleteDirectory() refuses a symlink in both modes (StorageException): the local adapter would
 *   delete the contents of the link target, also outside the root
 *
 * A type `link` is never reported. Links inside a path are followed by the operating system;
 * exists(), openFile(), fileStream() follow a link as last segment, deleteFile() removes the link
 * itself. The checks need the creocoder LocalFilesystem component: for a bare FilesystemOperator
 * the facade cannot see the adapter, and links behave as Flysystem 3 handles them.
 *
 * ## Requests
 *
 * The number of adapter requests per method is documented where it matters for remote adapters
 * (S3): fileStat() and fileStream() make exactly one request each and no existence check.
 * The existence check `has` costs one request for an existing file and two (a file and a
 * directory check) for a directory or a missing path on S3; `fileSize` is one HEAD request.
 *
 * Statements marked "local adapter" were verified against the local adapter only; behaviour of
 * other adapters is whatever their Flysystem 3 adapter does.
 */
class Storage extends BaseObject
{
    /**
     * The filesystem to work on: a yii filesystem component of creocoder/yii2-flysystem 2
     * (creocoder\flysystem\Filesystem) or a Flysystem 3 filesystem
     * (League\Flysystem\FilesystemOperator). This construction seam is the only place where the
     * library's types enter the facade; init() unwraps the component once.
     *
     * @var object
     */
    public $filesystem;

    /**
     * @var FilesystemOperator the Flysystem 3 filesystem behind $filesystem
     */
    private $operator;

    /**
     * @var string|null root directory on disk, only for a creocoder LocalFilesystem component
     */
    private $localRoot;

    /**
     * @var int link handling of that LocalFilesystem component (LocalFilesystemAdapter::*_LINKS)
     */
    private $linkHandling = LocalFilesystemAdapter::DISALLOW_LINKS;

    /**
     * @inheritdoc
     *
     * @throws InvalidConfigException if $filesystem is none of the accepted types
     */
    public function init()
    {
        parent::init();
        $this->operator = self::operatorOf($this->filesystem);
        if ($this->operator === null) {
            throw new InvalidConfigException(
                'Storage::$filesystem must be a creocoder\flysystem\Filesystem or a League\Flysystem\FilesystemOperator.'
            );
        }
        if ($this->filesystem instanceof LocalFilesystem) {
            $this->localRoot = rtrim($this->filesystem->path, '/\\');
            $this->linkHandling = $this->filesystem->linkHandling;
        }
    }

    /**
     * Whether the value can be used as Storage::$filesystem (Module checks its component with it).
     *
     * @param mixed $filesystem
     *
     * @return bool
     */
    public static function accepts($filesystem)
    {
        return self::operatorOf($filesystem) !== null;
    }

    /**
     * Mime type for a file name, by its extension only (no request, the file need not exist).
     *
     * The extension is lower-cased; unknown or missing extension: `text/plain` (never an empty
     * value). The table is the one of league/mime-type-detection, which Flysystem uses.
     *
     * @param string $fileName file name or path
     *
     * @return string
     */
    public static function mimeTypeByFileName($fileName)
    {
        $extension = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
        return self::mimeTypeByExtension($extension) ?: 'text/plain';
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
        return (new GeneratedExtensionToMimeTypeMap())->lookupMimeType((string)$extension);
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
            return (new WhitespacePathNormalizer())->normalizePath((string)$path);
        } catch (PathTraversalDetected | CorruptedPathDetected $e) {
            throw new InvalidPathException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Whether a file or directory exists at the path. Always `false` for the root.
     *
     * Requests: `has`.
     *
     * @param string $path
     *
     * @return bool
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function exists($path)
    {
        return $this->has($this->normalizePath($path));
    }

    /**
     * Type and size of an existing item (no mime type; type `file` or `dir`).
     *
     * Requests: `fileSize` (S3: HeadObject), and if that finds no file `directoryExists` (S3: one
     * ListObjectsV2): one for a file, two for a directory or a missing path.
     *
     * @param string $path
     *
     * @return ItemInfo|null `null` for a link with SKIP_LINKS (local adapter)
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException e.g. for a link with DISALLOW_LINKS (local adapter)
     */
    public function itemInfo($path)
    {
        $path = $this->normalizePath($path);
        $info = $this->stat($path);
        if ($info === null) {
            throw new ItemNotFoundException('File not found at path: ' . $path);
        }
        return $this->isSkippedLink($path) ? null : $info;
    }

    /**
     * Type and size for delivering a file: exactly ONE metadata request to the adapter
     * (`fileSize`, S3: HeadObject), no existence check. Used with fileStream() by `stream` and
     * `download`, where every request counts on remote adapters.
     *
     * Only files are reported (type `file`, the size, no mime type). A directory, a missing path
     * and the root give `null`: the `fileSize` request fails for all of them, and telling them
     * apart would cost another request. Callers answer `null` and every exception as "not found".
     *
     * @param string $path
     *
     * @return ItemInfo|null
     * @throws InvalidPathException
     * @throws StorageException for a link with DISALLOW_LINKS (local adapter) and other failures
     */
    public function fileStat($path)
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return null;
        }
        $size = $this->fileSize($path);
        if ($size === null || $this->isSkippedLink($path)) {
            return null;
        }
        return new ItemInfo('file', $size, null);
    }

    /**
     * Read stream of a file for delivery: exactly ONE request to the adapter (`readStream`, S3:
     * GetObject), no existence check (the partner of fileStat()). The caller closes the stream
     * (or hands it to the response).
     *
     * @param string $path
     *
     * @return resource|null `null` if the adapter provides no stream
     * @throws InvalidPathException
     * @throws StorageException if the adapter cannot read the path (e.g. a missing file)
     */
    public function fileStream($path)
    {
        return $this->readStream($this->normalizePath($path));
    }

    /**
     * Read stream of an existing item: an existence check (`has`) plus one read request.
     *
     * The local adapter opens directories too (the stream then fails on the first read, with a
     * PHP notice on some systems); callers that must not read directories check the type first.
     *
     * @param string $path
     *
     * @return resource|null `null` if the adapter provides no stream
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function openFile($path)
    {
        $path = $this->normalizePath($path);
        $this->assertSourceExists($path);
        return $this->readStream($path);
    }

    /**
     * Files and directories in a directory, sorted by path case-insensitively (strcasecmp).
     *
     * - The listing of a missing path or of a file is empty (no exception).
     * - Shallow: the direct children. Recursive: every item beneath the directory, directories
     *   included as entries of their own (S3: only directories with a marker object).
     * - `type` is `file` or `dir`; links: see the class doc.
     * - `path` of each item is relative to the storage root (not to the listed directory).
     *
     * Requests: one listing (S3: one ListObjectsV2 per 1000 keys).
     *
     * @param string $path directory, `/` or `` for the root
     * @param bool $recursive
     *
     * @return StorageItem[]
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function listItems($path, $recursive = false)
    {
        $path = $this->normalizePath($path);
        $items = $this->call(function () use ($path, $recursive) {
            $items = [];
            foreach ($this->operator->listContents($path, (bool)$recursive) as $attributes) {
                $items[] = $this->toItem($attributes);
            }
            return $items;
        });
        usort($items, static function (StorageItem $a, StorageItem $b) {
            return strcasecmp($a->getPath(), $b->getPath());
        });
        return $items;
    }

    /**
     * Last modification time of an existing item as unix time: an existence check (`has`) plus
     * one metadata request (`lastModified`, S3: HeadObject).
     *
     * @param string $path
     *
     * @return int|null `null` for a link with SKIP_LINKS (local adapter)
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException e.g. for a link with DISALLOW_LINKS (local adapter), or where the
     *         adapter has no time for the item
     */
    public function lastModified($path)
    {
        $path = $this->normalizePath($path);
        $this->assertSourceExists($path);
        if ($this->isSkippedLink($path)) {
            return null;
        }
        return $this->call(function () use ($path) {
            return $this->operator->lastModified($path);
        });
    }

    /**
     * Create a directory, including all missing parent directories.
     *
     * An existing directory at the path counts as success (`true`, nothing changes: the facade
     * checks first, because the local adapter would reset the permissions of an existing
     * directory). An existing file at the path (or as one of the parents) makes it fail with
     * `false` (local adapter). Creating the root succeeds without effect and without request.
     *
     * Requests: `directoryExists`, then `createDirectory` (S3: one PutObject of a marker object).
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
        if ($path === '' || $this->directoryExists($path)) {
            return true;
        }
        return $this->perform(UnableToCreateDirectory::class, function () use ($path) {
            $this->operator->createDirectory($path);
        });
    }

    /**
     * Delete a file.
     *
     * Requests: `has`, then `delete`.
     *
     * @param string $path
     *
     * @return bool `false` if the adapter fails, which the local adapter does for a directory at
     *         the path (it is left untouched)
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function deleteFile($path)
    {
        $path = $this->normalizePath($path);
        $this->assertSourceExists($path);
        return $this->perform(UnableToDeleteFile::class, function () use ($path) {
            $this->operator->delete($path);
        });
    }

    /**
     * Delete a directory with everything beneath it (always recursive; links beneath it are
     * removed, not followed).
     *
     * There is no existence check that throws: a missing path, or a file at the path, gives
     * `false` and nothing changes (Flysystem 3 itself would report success).
     *
     * Requests: `directoryExists`, then `deleteDirectory` (S3: a listing plus one DeleteObjects
     * per 1000 keys).
     *
     * @param string $path
     *
     * @return bool
     * @throws InvalidPathException for the root (never deleted) and for refused paths
     * @throws StorageException for a symlink at the path (local adapter, any link handling), and
     *         when the adapter fails, e.g. on an item beneath the directory it cannot delete (local
     *         adapter; the items deleted up to that point stay deleted)
     */
    public function deleteDirectory($path)
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            throw new InvalidPathException('Root directories can not be deleted.');
        }
        if (!$this->directoryExists($path)) {
            return false;
        }
        if ($this->isLink($path)) {
            throw new StorageException('A symbolic link is not deleted as directory, path: ' . $path);
        }
        return $this->call(function () use ($path) {
            try {
                $this->operator->deleteDirectory($path);
            } catch (\UnexpectedValueException $e) {
                // the local adapter lets a failure of its directory iterator pass (an unreadable
                // directory beneath)
                throw new StorageException($e->getMessage(), 0, $e);
            }
            return true;
        });
    }

    /**
     * Move or rename a file or directory within this storage. Missing parent directories of the
     * target are created (local adapter).
     *
     * Order: the source is checked first, then the target.
     *
     * Requests: `has` for the source, `has` for the target, then `move` (S3: CopyObject plus
     * DeleteObject; `retain_visibility` is off, so no GetObjectAcl).
     *
     * @param string $from
     * @param string $to
     *
     * @return bool `true`; a failure of the adapter throws
     * @throws ItemNotFoundException if nothing exists at $from
     * @throws ItemExistsException if anything exists at $to (also the root), also when $to equals
     *         $from; nothing is moved
     * @throws InvalidPathException
     * @throws StorageException if the adapter fails, e.g. for a directory moved into itself or a
     *         target below a file (local adapter)
     */
    public function move($from, $to)
    {
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        $this->assertSourceExists($from);
        $this->assertTargetAbsent($to);
        return $this->call(function () use ($from, $to) {
            $this->operator->move($from, $to, [Config::OPTION_RETAIN_VISIBILITY => false]);
            return true;
        });
    }

    /**
     * Copy a file within this storage. Missing parent directories of the target are created; the
     * copy gets the current time as modification time and the permissions of a new file (local
     * adapter).
     *
     * Order: the source is checked first, then the target. Copying a directory is not supported:
     * the adapter fails (StorageException).
     *
     * Requests: `has` for the source, `has` for the target, then `copy` (S3: CopyObject;
     * `retain_visibility` is off, so no GetObjectAcl).
     *
     * @param string $from
     * @param string $to
     *
     * @return bool `true`; a failure of the adapter throws
     * @throws ItemNotFoundException if nothing exists at $from
     * @throws ItemExistsException if anything exists at $to (also the root); nothing is copied
     * @throws InvalidPathException
     * @throws StorageException if the adapter fails
     */
    public function copyFile($from, $to)
    {
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        $this->assertSourceExists($from);
        $this->assertTargetAbsent($to);
        return $this->call(function () use ($from, $to) {
            $this->operator->copy($from, $to, [Config::OPTION_RETAIN_VISIBILITY => false]);
            return true;
        });
    }

    /**
     * Write a new file from a stream. The stream is rewound first if it is seekable; missing
     * parent directories are created (local adapter). The stream is not closed.
     *
     * The local adapter creates the target file before it copies the content, so a stream that
     * fails while being read (e.g. one opened on a directory) leaves an empty file behind.
     *
     * Requests: `has`, then `writeStream` (S3: one PutObject).
     *
     * @param string $path
     * @param resource $stream
     * @param string|false|null $mimeType content type to store with the file where the adapter
     *        supports it (S3, config key `mimetype`), `null` to let the adapter decide; ignored by
     *        the local adapter
     *
     * @return bool `false` if the adapter reports failure
     * @throws ItemExistsException if a file or directory exists at the path (also the root);
     *         nothing is written
     * @throws \InvalidArgumentException if $stream is no stream resource
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function writeFile($path, $stream, $mimeType = null)
    {
        $path = $this->normalizePath($path);
        $this->assertStream($stream);
        $this->assertTargetAbsent($path);
        return $this->write($path, $stream, $mimeType);
    }

    /**
     * Write a file from a stream, replacing the content of an existing FILE at the path: the only
     * operation of this class that may replace anything. Without an item at the path it behaves
     * like writeFile() (missing parent directories are created). The stream is rewound first if it
     * is seekable and is not closed. The replaced file gets the current time as modification time
     * (local adapter).
     *
     * A directory at the path (also the root) is never replaced or deleted: the call is refused
     * with ItemExistsException and nothing changes.
     *
     * Requests: `fileSize` (finds a file to replace), otherwise also `directoryExists`, then the
     * write. Like the other writes this is not atomic.
     *
     * @param string $path
     * @param resource $stream
     * @param string|false|null $mimeType see writeFile()
     *
     * @return bool `false` if the adapter reports failure
     * @throws ItemExistsException if a directory (or anything else that is not a file) is at the path
     * @throws \InvalidArgumentException if $stream is no stream resource
     * @throws InvalidPathException
     * @throws StorageException e.g. for a link with DISALLOW_LINKS at the path (local adapter)
     */
    public function replaceFile($path, $stream, $mimeType = null)
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            throw new ItemExistsException('The root directory can not be replaced by a file.');
        }
        $this->assertStream($stream);
        $info = $this->stat($path);
        if ($info !== null && ($this->isSkippedLink($path) || $info->getType() !== 'file')) {
            throw new ItemExistsException('Only a file can be replaced, not the item at path: ' . $path);
        }
        return $this->write($path, $stream, $mimeType);
    }

    /**
     * The FilesystemOperator for an accepted value, `null` for anything else: the one place where
     * a creocoder component is unwrapped.
     *
     * @param mixed $filesystem
     *
     * @return FilesystemOperator|null
     */
    private static function operatorOf($filesystem)
    {
        if ($filesystem instanceof FilesystemComponent) {
            $filesystem = $filesystem->getFilesystem();
        }
        return $filesystem instanceof FilesystemOperator ? $filesystem : null;
    }

    /**
     * Existence check of a source: ItemNotFoundException for the root and for a missing path.
     *
     * @param string $path normalised
     *
     * @throws ItemNotFoundException
     */
    private function assertSourceExists($path)
    {
        if (!$this->has($path)) {
            throw new ItemNotFoundException('File not found at path: ' . $path);
        }
    }

    /**
     * Existence check of a target: ItemExistsException for the root and for any existing file or
     * directory. Flysystem 3 replaces existing files silently, so every refusal of the facade
     * goes through here (replaceFile() has its own check).
     *
     * @param string $path normalised
     *
     * @throws ItemExistsException
     */
    private function assertTargetAbsent($path)
    {
        if ($path === '' || $this->has($path)) {
            throw new ItemExistsException('File already exists at path: ' . $path);
        }
    }

    /**
     * @param mixed $stream
     *
     * @throws \InvalidArgumentException if $stream is no stream resource
     */
    private function assertStream($stream)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('The content to write must be a stream resource.');
        }
    }

    /**
     * Type and size of an item, `null` if nothing exists at the path (also the root): `fileSize`,
     * and if that finds no file `directoryExists`. Links are not looked at (see isSkippedLink()).
     *
     * @param string $path normalised
     *
     * @return ItemInfo|null
     */
    private function stat($path)
    {
        if ($path === '') {
            return null;
        }
        $size = $this->fileSize($path);
        if ($size !== null) {
            return new ItemInfo('file', $size, null);
        }
        if ($this->directoryExists($path)) {
            return new ItemInfo('dir', null, null);
        }
        return null;
    }

    /**
     * Adapter: file or directory at the path (`false` for the root).
     *
     * @param string $path normalised
     *
     * @return bool
     */
    private function has($path)
    {
        if ($path === '') {
            return false;
        }
        return $this->call(function () use ($path) {
            return $this->operator->has($path);
        });
    }

    /**
     * Adapter: directory at the path.
     *
     * @param string $path normalised
     *
     * @return bool
     */
    private function directoryExists($path)
    {
        return $this->call(function () use ($path) {
            return $this->operator->directoryExists($path);
        });
    }

    /**
     * Adapter: size of a file, `null` where the adapter reports none (a directory, a missing path).
     *
     * @param string $path normalised
     *
     * @return int|null
     */
    private function fileSize($path)
    {
        return $this->call(function () use ($path) {
            try {
                return $this->operator->fileSize($path);
            } catch (UnableToRetrieveMetadata $e) {
                return null;
            }
        });
    }

    /**
     * Adapter: read stream.
     *
     * @param string $path normalised
     *
     * @return resource|null
     */
    private function readStream($path)
    {
        $stream = $this->call(function () use ($path) {
            return $this->operator->readStream($path);
        });
        return is_resource($stream) ? $stream : null;
    }

    /**
     * Adapter: write a file, replacing an existing one (callers check the target first).
     *
     * @param string $path normalised
     * @param resource $stream
     * @param string|false|null $mimeType
     *
     * @return bool
     */
    private function write($path, $stream, $mimeType)
    {
        $config = $mimeType === null ? [] : ['mimetype' => $mimeType];
        return $this->perform(UnableToWriteFile::class, function () use ($path, $stream, $config) {
            $this->operator->writeStream($path, $stream, $config);
        });
    }

    /**
     * Whether the last segment of the path is a symlink on the local disk (always `false` for
     * other adapters). Makes no adapter request.
     *
     * @param string $path normalised
     *
     * @return bool
     */
    private function isLink($path)
    {
        return $this->localRoot !== null && $path !== '' && is_link($this->localRoot . '/' . $path);
    }

    /**
     * Applies the link handling of the local component to a link as last path segment: throws
     * StorageException with DISALLOW_LINKS, returns `true` with SKIP_LINKS, `false` for anything
     * that is no link.
     *
     * @param string $path normalised
     *
     * @return bool
     * @throws StorageException
     */
    private function isSkippedLink($path)
    {
        if (!$this->isLink($path)) {
            return false;
        }
        if ($this->linkHandling & LocalFilesystemAdapter::SKIP_LINKS) {
            return true;
        }
        $e = SymbolicLinkEncountered::atLocation($path);
        throw new StorageException($e->getMessage(), 0, $e);
    }

    /**
     * Runs a writing operation of the library: `true` if it returns, `false` if it fails with its
     * own failure class (the operation was performed and failed); every other exception of the
     * library is translated by call().
     *
     * @param string $failureClass e.g. UnableToWriteFile::class
     * @param callable $operation
     *
     * @return bool
     */
    private function perform($failureClass, callable $operation)
    {
        return $this->call(function () use ($failureClass, $operation) {
            try {
                $operation();
            } catch (FilesystemException $e) {
                if (!$e instanceof $failureClass) {
                    throw $e;
                }
                \Yii::info($e->getMessage(), __METHOD__);
                return false;
            }
            return true;
        });
    }

    /**
     * Runs calls of the library and translates its exceptions into the facade's: the only place
     * where exceptions of the library are mapped.
     *
     * - PathTraversalDetected, CorruptedPathDetected: InvalidPathException (not expected, the
     *   facade normalises every path first)
     * - InvalidStreamProvided: passed on, it is an \InvalidArgumentException
     * - every other FilesystemException (UnableTo*, SymbolicLinkEncountered, ...): StorageException
     *
     * @param callable $operation
     *
     * @return mixed
     */
    private function call(callable $operation)
    {
        try {
            return $operation();
        } catch (PathTraversalDetected | CorruptedPathDetected $e) {
            throw new InvalidPathException($e->getMessage(), 0, $e);
        } catch (InvalidStreamProvided $e) {
            throw $e;
        } catch (FilesystemException $e) {
            throw new StorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The only place where listing items are built.
     *
     * Flysystem 3 reports `path` (relative to the root, no leading slash), `type`, `file_size`
     * (files only) and `last_modified`; `dirname`, `basename` and `extension` are derived from
     * `path` as Flysystem 1 did it (Util::pathinfo(): the part before and after the last `/`,
     * `extension` from PHP's pathinfo() on the basename).
     *
     * @param StorageAttributes $attributes listing entry of the library
     *
     * @return StorageItem
     */
    private function toItem(StorageAttributes $attributes)
    {
        $path = $attributes->path();
        $slash = strrpos($path, '/');
        $dirname = $slash === false ? '' : substr($path, 0, $slash);
        $basename = $slash === false ? $path : substr($path, $slash + 1);
        $pathInfo = pathinfo($basename);
        return new StorageItem(
            $path,
            $attributes->type(),
            $dirname,
            $basename,
            isset($pathInfo['extension']) ? $pathInfo['extension'] : null,
            $attributes instanceof FileAttributes ? $attributes->fileSize() : null,
            $attributes->lastModified()
        );
    }
}
