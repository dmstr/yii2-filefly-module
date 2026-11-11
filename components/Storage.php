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

use creocoder\flysystem\AwsS3Filesystem;
use creocoder\flysystem\Filesystem as FilesystemComponent;
use creocoder\flysystem\FtpFilesystem;
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
 * ## Backends
 *
 * The contract above holds on the local adapter, on S3 (AwsS3Filesystem) and on FTP/FTPS
 * (FtpFilesystem); tests/adapters/suite runs the same contract tests against all of them. What
 * the facade does per backend to keep it, and the few documented deviations:
 *
 * - S3 is an object store with a flat key space, recognised once in init() from the component
 *   class. A directory is a key prefix: it exists while a marker object (`dir/`, written by
 *   createDirectory()) or any object below it exists. To keep the hierarchy of the other
 *   adapters the facade refuses keys below a file key (one HeadObject per parent level on
 *   writeFile(), replaceFile() of a new file, copyFile(), move() and createDirectory()), writes a
 *   marker for the parent before an item is deleted or moved away (so a directory never vanishes
 *   with its last item, one PutObject), adds the directories between the listed one and an
 *   entry to recursive listings (no request), and moves a directory object by object (see
 *   move()). Deviation: directories have no time (listing timestamp and lastModified() `null`).
 * - FTP: ext-ftp raises PHP warnings ahead of the adapter's exception; the facade holds them back
 *   while it calls the library and drops them when the library reports the failure, so the
 *   failure ends in the facade's exceptions and not in an ErrorException of the application
 *   (see libraryCall()). ProFTPD's odd listing entry for a file path is dropped. Deviations:
 *   lastModified() of a directory is `null` (MDTM answers for files only), listing timestamps
 *   have minute precision (`ls` format, only with `timestampsOnUnixListingsEnabled`).
 * - Local: openFile() and fileStream() of a directory do not fail (see openFile()).
 *
 * Nothing of this is atomic: every check-then-act is two or more requests on every backend.
 *
 * ## Requests
 *
 * The number of adapter requests per method is documented where it matters for remote adapters.
 * fileStat() and fileStream() make exactly one request each and no existence check; a refused
 * path makes none. On S3 (pinned by the adapter suite, an item in the root; `+1 HeadObject` per
 * parent level of a target below the root):
 *
 * | method | S3 requests |
 * | --- | --- |
 * | fileStat() + fileStream() of a file | HeadObject, GetObject |
 * | fileStat() of a missing file | HeadObject |
 * | exists() of a file | HeadObject |
 * | exists() of a directory or a missing path | HeadObject, ListObjectsV2 |
 * | listItems(), shallow or recursive | ListObjectsV2 per 1000 keys |
 * | writeFile() | HeadObject, ListObjectsV2, PutObject |
 * | copyFile() | HeadObject, HeadObject, ListObjectsV2, HeadObject, CopyObject |
 * | move() of a file | HeadObject, HeadObject, ListObjectsV2, HeadObject, CopyObject, DeleteObject |
 * | lastModified() of a file | HeadObject, HeadObject |
 * | lastModified() of a directory (`null`) | HeadObject, ListObjectsV2 |
 * | any method with a refused path | none |
 *
 * move() of a file out of its directory adds one PutObject (the parent's marker), deleteFile()
 * and deleteDirectory() of an item below the root as well.
 *
 * Statements marked "local adapter" were verified against the local adapter only.
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
     * @var bool whether the storage is an object store with a flat key space (the creocoder
     *      AwsS3Filesystem component): directories are key prefixes and marker objects, the store
     *      does not refuse a key below a file key, and a directory cannot be moved natively
     */
    private $objectStore = false;

    /**
     * @var bool whether the adapter raises PHP warnings ahead of its own exception (the creocoder
     *      FtpFilesystem component: ext-ftp warns, the adapter then throws), see libraryCall()
     */
    private $warnsBeforeFailing = false;

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
        // the one place where the kind of backend is told apart; resolved once per instance
        $this->objectStore = $this->filesystem instanceof AwsS3Filesystem;
        $this->warnsBeforeFailing = $this->filesystem instanceof FtpFilesystem;
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
     *   included as entries of their own (S3: also the directories that have no marker object,
     *   derived from the paths of the entries below them, timestamp `null`).
     * - Only entries below the listed directory with a normalised path are returned (ProFTPD
     *   answers the listing of a file with an entry carrying the absolute server path).
     * - `timestamp`: S3 reports none for directories; FTP reports minute precision, and only with
     *   `timestampsOnUnixListingsEnabled`.
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
        $prefix = $path === '' ? '' : $path . '/';
        $items = $this->call(function () use ($path, $prefix, $recursive) {
            $items = [];
            foreach ($this->operator->listContents($path, (bool)$recursive) as $attributes) {
                // only entries below the listed directory with a normalised path: ProFTPD answers
                // the listing of a file with one entry that carries the absolute server path
                // (`f.txt//<server root>/f.txt/`), which must never reach a caller
                $itemPath = $attributes->path();
                if (!$this->isListingPath($itemPath, $prefix)) {
                    continue;
                }
                $items[$itemPath] = $this->toItem($attributes);
            }
            return $items;
        });
        if ($recursive) {
            // a deep S3 listing has entries only for directories with a marker object: every
            // directory between the listed one and an entry is added (no request)
            foreach (array_keys($items) as $itemPath) {
                for ($parent = $this->parentOf($itemPath); strlen($parent) > strlen($path); $parent = $this->parentOf($parent)) {
                    if (!isset($items[$parent])) {
                        $items[$parent] = $this->makeItem($parent, 'dir', null, null);
                    }
                }
            }
        }
        $items = array_values($items);
        usort($items, static function (StorageItem $a, StorageItem $b) {
            return strcasecmp($a->getPath(), $b->getPath());
        });
        return $items;
    }

    /**
     * Last modification time of an existing item as unix time: a type check (fileSize, and for
     * no file directoryExists) plus one metadata request (`lastModified`, S3: HeadObject); a
     * file costs two HeadObject on S3.
     *
     * Directories: the local adapter reports their time; S3 has none (`null`, no further
     * request), FTP answers MDTM for files only (`null`).
     *
     * @param string $path
     *
     * @return int|null `null` for a link with SKIP_LINKS (local adapter) and for a directory on S3
     *         and FTP
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException e.g. for a link with DISALLOW_LINKS (local adapter), or where the
     *         adapter has no time for the item
     */
    public function lastModified($path)
    {
        $path = $this->normalizePath($path);
        $info = $this->stat($path);
        if ($info === null) {
            throw new ItemNotFoundException('File not found at path: ' . $path);
        }
        if ($this->isSkippedLink($path)) {
            return null;
        }
        if ($info->getType() === 'dir') {
            if ($this->objectStore) {
                // a key prefix has no time
                return null;
            }
            return $this->call(function () use ($path) {
                try {
                    return $this->operator->lastModified($path);
                } catch (UnableToRetrieveMetadata $e) {
                    // FTP: MDTM answers for files only
                    return null;
                }
            });
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
     * `false` on every backend (the facade checks the path itself, ProFTPD would report success
     * and S3 would add a marker next to the file). Creating the root succeeds without effect and
     * without request.
     *
     * Requests: `directoryExists`, `fileExists`, then `createDirectory` (S3: ListObjectsV2,
     * HeadObject, one HeadObject per parent level, one PutObject of a marker object for the
     * deepest level; the levels above exist through it).
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
        // a file at the path: ProFTPD would report success without effect, S3 would add a marker
        // object next to the file
        if ($this->fileExists($path)) {
            return false;
        }
        try {
            $this->assertParentsAreDirectories($path);
        } catch (StorageException $e) {
            return false;
        }
        return $this->perform(UnableToCreateDirectory::class, function () use ($path) {
            $this->operator->createDirectory($path);
        });
    }

    /**
     * Delete a file. Its directory stays, also when it becomes empty (S3: a marker object is
     * written for it first).
     *
     * Requests: `fileExists` (for no file also `directoryExists`), then `delete` (S3: HeadObject,
     * PutObject of the parent's marker for a file below the root, DeleteObject).
     *
     * @param string $path
     *
     * @return bool `false` if the adapter fails, and for a directory at the path on every backend
     *         (it is left untouched; S3 and FTP would report success without effect). A symlink
     *         to a directory is removed as link (local adapter)
     * @throws ItemNotFoundException if nothing exists at the path, also for the root
     * @throws InvalidPathException
     * @throws StorageException
     */
    public function deleteFile($path)
    {
        $path = $this->normalizePath($path);
        if (!$this->fileExists($path)) {
            $isDirectory = $path !== '' && $this->directoryExists($path);
            if (!$isDirectory) {
                throw new ItemNotFoundException('File not found at path: ' . $path);
            }
            if (!$this->isLink($path)) {
                // a directory: S3 and FTP would report success without effect
                return false;
            }
            // a link to a directory (local adapter): the adapter removes the link itself
        }
        $this->keepParentDirectory($path);
        return $this->perform(UnableToDeleteFile::class, function () use ($path) {
            $this->operator->delete($path);
        });
    }

    /**
     * Delete a directory with everything beneath it (always recursive; links beneath it are
     * removed, not followed).
     *
     * There is no existence check that throws: a missing path, or a file at the path, gives
     * `false` and nothing changes on every backend (Flysystem 3 itself would report success on
     * local and S3 and throw on FTP). Siblings that share the name prefix (`d-other`, `d.txt` for
     * `d`) are not touched; the parent directory stays (S3: its marker is written first).
     *
     * Requests: `directoryExists`, then `deleteDirectory` (S3: ListObjectsV2, PutObject of the
     * parent's marker below the root, then a listing plus one DeleteObjects per 1000 keys).
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
        $this->keepParentDirectory($path);
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
     * target are created; the parent directory of the source stays.
     *
     * Order: the source is checked first, then the target.
     *
     * Directories on S3: there is no directory object to move, so the facade moves it object by
     * object (moveDirectoryObjects()): list the source, copy every file and marker below the
     * target, then delete the source by prefix. If that fails half-way, StorageException says in
     * which phase: while copying the source is unchanged and the target holds a partial copy;
     * while deleting the target is complete. Nothing is ever replaced (the target did not exist).
     * The local adapter and FTP rename natively. Moving a directory into itself fails on every
     * backend without change.
     *
     * Requests: the source's type (`fileSize`, for no file `directoryExists`), `has` for the
     * target, then `move` (S3: CopyObject plus DeleteObject; `retain_visibility` is off, so no
     * GetObjectAcl; see the table in the class doc).
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
        $source = $this->stat($from);
        if ($source === null) {
            throw new ItemNotFoundException('File not found at path: ' . $from);
        }
        $this->assertTargetAbsent($to);
        $this->assertParentsAreDirectories($to);
        if ($this->objectStore && $source->getType() === 'dir') {
            return $this->moveDirectoryObjects($from, $to);
        }
        if (strpos($to, $this->parentOf($from) . '/') !== 0) {
            $this->keepParentDirectory($from);
        }
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
     * Requests: `has` for the source, `has` for the target, then `copy` (S3: HeadObject plus
     * CopyObject; `retain_visibility` is off, so no GetObjectAcl; see the table in the class doc).
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
        $this->assertParentsAreDirectories($to);
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
     * A parent that is a file makes it fail with StorageException on every backend (the local
     * adapter cannot create the directory, FTP reports a failed upload, S3 is checked by the
     * facade, one HeadObject per parent level).
     *
     * Requests: `has`, then `writeStream` (S3: HeadObject, ListObjectsV2, PutObject).
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
        $this->assertParentsAreDirectories($path);
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
        if ($info === null) {
            $this->assertParentsAreDirectories($path);
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
     * Adapter: file at the path (`false` for the root).
     *
     * @param string $path normalised
     *
     * @return bool
     */
    private function fileExists($path)
    {
        if ($path === '') {
            return false;
        }
        return $this->call(function () use ($path) {
            return $this->operator->fileExists($path);
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
        $written = $this->perform(UnableToWriteFile::class, function () use ($path, $stream, $config) {
            $this->operator->writeStream($path, $stream, $config);
        });
        $parent = $this->parentOf($path);
        if (!$written && $parent !== '' && !$this->directoryExists($parent)) {
            // the parent could not be created, as on the local adapter (which fails earlier, with
            // UnableToCreateDirectory): FTP reports a write below a file as a failed upload
            throw new StorageException('A parent of the path is not a directory.');
        }
        return $written;
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
        try {
            $this->call($operation);
        } catch (StorageException $e) {
            if (!$e->getPrevious() instanceof $failureClass) {
                throw $e;
            }
            \Yii::info($e->getMessage(), __METHOD__);
            return false;
        }
        return true;
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
            return $this->libraryCall($operation);
        } catch (PathTraversalDetected | CorruptedPathDetected $e) {
            throw new InvalidPathException($e->getMessage(), 0, $e);
        } catch (InvalidStreamProvided $e) {
            throw $e;
        } catch (FilesystemException $e) {
            throw new StorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Runs calls of the library; on an adapter that warns before it fails (FTP: ext-ftp raises a
     * PHP warning, e.g. `ftp_fput(): ...`, and the adapter then throws its own exception), the
     * warnings raised meanwhile are held back:
     *
     * - the library throws one of its exceptions: the warnings belonged to that failure and are
     *   dropped, the exception is translated as on every other adapter (call())
     * - otherwise (success, or any other exception): every held warning is passed to the error
     *   handler that was active before, unchanged, so a real error is never swallowed
     *
     * Warnings silenced with `@` stay silenced. Other adapters run the operation unchanged.
     *
     * @param callable $operation
     *
     * @return mixed
     */
    private function libraryCall(callable $operation)
    {
        if (!$this->warnsBeforeFailing) {
            return $operation();
        }
        $held = [];
        $previous = set_error_handler(static function ($level, $message, $file = '', $line = 0) use (&$held) {
            if (!(error_reporting() & $level)) {
                return false;
            }
            $held[] = [$level, $message, $file, $line];
            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
        try {
            $result = $operation();
        } catch (FilesystemException $e) {
            restore_error_handler();
            throw $e;
        } catch (\Throwable $e) {
            restore_error_handler();
            self::passOn($held, $previous);
            throw $e;
        }
        restore_error_handler();
        self::passOn($held, $previous);
        return $result;
    }

    /**
     * Hands held PHP warnings to the error handler they were raised under (PHP's own handling,
     * as E_USER_WARNING, if there was none).
     *
     * @param array[] $held [level, message, file, line] each
     * @param callable|null $handler
     */
    private static function passOn(array $held, $handler)
    {
        foreach ($held as [$level, $message, $file, $line]) {
            if ($handler !== null) {
                $handler($level, $message, $file, $line);
            } else {
                trigger_error($message, E_USER_WARNING);
            }
        }
    }

    /**
     * Object store: a write, copy or move below a key that is a file is refused, as the
     * hierarchical adapters refuse it (the store itself would accept `f.txt/child`). One
     * `fileExists` (S3: HeadObject) per parent level; no request on other adapters, which refuse
     * it themselves.
     *
     * @param string $path normalised
     *
     * @throws StorageException
     */
    private function assertParentsAreDirectories($path)
    {
        if (!$this->objectStore) {
            return;
        }
        $segments = explode('/', $path);
        array_pop($segments);
        $parent = '';
        foreach ($segments as $segment) {
            $parent = $parent === '' ? $segment : $parent . '/' . $segment;
            if ($this->fileExists($parent)) {
                throw new StorageException('A parent of the path is a file.');
            }
        }
    }

    /**
     * Object store: a directory exists only as long as a key below it exists, so before an item
     * is deleted or moved away its parent directory gets a marker object (S3: one PutObject;
     * idempotent), and the parent stays as empty directory, as on the hierarchical adapters.
     * No request for an item in the root and on other adapters.
     *
     * @param string $path normalised, the item that goes away
     */
    private function keepParentDirectory($path)
    {
        $parent = $this->parentOf($path);
        if (!$this->objectStore || $parent === '') {
            return;
        }
        $this->call(function () use ($parent) {
            $this->operator->createDirectory($parent);
        });
    }

    /**
     * Object store: moves a directory object by object, as S3 has no directory to rename. The
     * caller has checked that the source is a directory and that nothing exists at the target.
     *
     * Order: the source is listed once (deep); the target gets a marker object; every file is
     * copied and every directory marker is created below the target; only then is the source
     * deleted by prefix. Nothing at the target is replaced (it did not exist).
     *
     * Failure half-way (StorageException): while copying, the source is complete and unchanged
     * and the target holds a partial copy, which is left in place (it was created by this call;
     * removing it could remove what others wrote meanwhile); while deleting the source, the target
     * is complete and the source may be partly deleted. Not atomic, like every operation here.
     *
     * Requests (S3): ListObjectsV2 per 1000 keys, one PutObject for the target, per file
     * HeadObject + CopyObject, per directory marker one PutObject, a marker for the parent of the
     * source (keepParentDirectory()), then ListObjectsV2 + DeleteObjects per 1000 keys.
     *
     * @param string $from normalised directory
     * @param string $to normalised, missing
     *
     * @return bool `true`
     * @throws StorageException
     */
    private function moveDirectoryObjects($from, $to)
    {
        if ($to === $from || strpos($to, $from . '/') === 0) {
            throw new StorageException('A directory can not be moved into itself.');
        }
        $entries = $this->call(function () use ($from) {
            $entries = [];
            foreach ($this->operator->listContents($from, true) as $attributes) {
                $entries[] = [$attributes->path(), $attributes->isDir()];
            }
            return $entries;
        });
        $offset = strlen($from);
        try {
            $this->call(function () use ($entries, $to, $offset) {
                $this->operator->createDirectory($to);
                foreach ($entries as [$path, $isDir]) {
                    $target = $to . substr($path, $offset);
                    if ($isDir) {
                        $this->operator->createDirectory($target);
                    } else {
                        $this->operator->copy($path, $target, [Config::OPTION_RETAIN_VISIBILITY => false]);
                    }
                }
            });
        } catch (StorageException $e) {
            throw new StorageException('Moving the directory failed while copying; the source is unchanged, the target holds a partial copy.', 0, $e);
        }
        $this->keepParentDirectory($from);
        try {
            $this->call(function () use ($from) {
                $this->operator->deleteDirectory($from);
            });
        } catch (StorageException $e) {
            throw new StorageException('Moving the directory failed while deleting the source; the target is complete.', 0, $e);
        }
        return true;
    }

    /**
     * Whether a path from a listing is a normalised path below the listed directory
     *
     * @param string $path as the adapter reports it
     * @param string $prefix the listed directory with trailing slash, `''` for the root
     *
     * @return bool
     */
    private function isListingPath($path, $prefix)
    {
        return $path !== ''
            && $path[0] !== '/'
            && substr($path, -1) !== '/'
            && strpos($path, '//') === false
            && ($prefix === '' || strpos($path, $prefix) === 0);
    }

    /**
     * The parent directory of a normalised path, `''` for an item in the root
     *
     * @param string $path normalised
     *
     * @return string
     */
    private function parentOf($path)
    {
        $slash = strrpos($path, '/');
        return $slash === false ? '' : substr($path, 0, $slash);
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
        return $this->makeItem(
            $attributes->path(),
            $attributes->type(),
            $attributes instanceof FileAttributes ? $attributes->fileSize() : null,
            $attributes->lastModified()
        );
    }

    /**
     * A listing item from its path and the values the adapter reports
     *
     * @param string $path relative to the root
     * @param string $type
     * @param int|null $size
     * @param int|null $timestamp
     *
     * @return StorageItem
     */
    private function makeItem($path, $type, $size, $timestamp)
    {
        $slash = strrpos($path, '/');
        $dirname = $slash === false ? '' : substr($path, 0, $slash);
        $basename = $slash === false ? $path : substr($path, $slash + 1);
        $pathInfo = pathinfo($basename);
        return new StorageItem(
            $path,
            $type,
            $dirname,
            $basename,
            isset($pathInfo['extension']) ? $pathInfo['extension'] : null,
            $size,
            $timestamp
        );
    }
}
