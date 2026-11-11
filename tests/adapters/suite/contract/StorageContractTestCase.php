<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

use creocoder\flysystem\Filesystem as FilesystemComponent;
use hrzg\filefly\components\Storage;
use hrzg\filefly\components\storage\InvalidPathException;
use hrzg\filefly\components\storage\ItemExistsException;
use hrzg\filefly\components\storage\ItemInfo;
use hrzg\filefly\components\storage\ItemNotFoundException;
use hrzg\filefly\components\storage\StorageException;
use hrzg\filefly\components\storage\StorageItem;
use hrzg\filefly\tests\adapters\suite\support\CountingOperator;
use hrzg\filefly\tests\adapters\suite\support\RawTree;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\di\Container;

/**
 * The contract of hrzg\filefly\components\Storage, method by method, run unchanged against every
 * backend: each concrete class only builds the component (rooted in a fresh, unique directory or
 * prefix per test) and names its documented deviations (see Deviation).
 *
 * Seeding and inspection go through the Flysystem operator of the component itself (`raw`), never
 * through the facade under test; tree() is the complete content of the test root (RawTree).
 */
abstract class StorageContractTestCase extends TestCase
{
    /** content of the large round trip file: 8 MiB plus a few bytes, below the S3 multipart threshold */
    protected const LARGE_SIZE = 8 * 1024 * 1024 + 7;

    /**
     * @var FilesystemComponent component under test, rooted at the test root
     */
    protected $component;

    /**
     * @var FilesystemOperator the component's Flysystem operator, for seeding and inspection
     */
    protected $raw;

    /**
     * @var Storage the facade under test
     */
    protected $storage;

    /**
     * Builds the component config of a fresh, empty test root (and creates that root where the
     * backend needs it to exist).
     */
    abstract protected function createTestRoot(): array;

    /**
     * Removes the test root with everything below it.
     */
    abstract protected function removeTestRoot(): void;

    /**
     * The documented deviations of this backend (Deviation constants), the one place where a
     * backend differs from the contract.
     *
     * @return string[]
     */
    protected function deviations(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->component = Yii::createObject($this->createTestRoot());
        $this->raw = $this->component->getFilesystem();
        $this->storage = new Storage(['filesystem' => $this->component]);
    }

    protected function tearDown(): void
    {
        $this->storage = null;
        $this->raw = null;
        $this->component = null;
        $this->removeTestRoot();
        Yii::$container = new Container();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    protected function deviates(string $deviation): bool
    {
        return in_array($deviation, $this->deviations(), true);
    }

    protected function seedFile(string $path, string $content = ''): void
    {
        $this->raw->write($path, $content);
    }

    protected function seedDir(string $path): void
    {
        $this->raw->createDirectory($path);
    }

    /**
     * @return resource
     */
    protected static function stream(string $content)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    /**
     * @param resource|null $stream
     */
    protected static function drain($stream): string
    {
        self::assertIsResource($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    /** deterministic pseudo random content of the given size */
    protected static function blob(int $bytes): string
    {
        $data = '';
        for ($i = 0; strlen($data) < $bytes; $i++) {
            $data .= hash('sha256', 'blob' . $i, true);
        }
        return substr($data, 0, $bytes);
    }

    /**
     * Complete content of the test root, see the class doc
     */
    protected function tree(): array
    {
        return RawTree::of($this->raw);
    }

    protected function assertTree(array $expected, string $message = ''): void
    {
        ksort($expected, SORT_STRING);
        $this->assertSame($expected, $this->tree(), $message ?: 'content of the test root');
    }

    /**
     * Asserts a listing completely: order, path, type, dirname, basename, extension, size, and the
     * timestamp as far as the backend reports it (see assertTimestamp()).
     *
     * @param array $expected list of [path, type, size]
     * @param StorageItem[] $items
     */
    protected function assertItems(array $expected, array $items, int $since, int $until): void
    {
        $actual = [];
        foreach ($items as $item) {
            $this->assertInstanceOf(StorageItem::class, $item);
            $actual[] = [
                $item->getPath(),
                $item->getType(),
                $item->getDirname(),
                $item->getBasename(),
                $item->getExtension(),
                $item->getFileSize(),
            ];
        }
        $wanted = [];
        foreach ($expected as [$path, $type, $size]) {
            $slash = strrpos($path, '/');
            $basename = $slash === false ? $path : substr($path, $slash + 1);
            $extension = pathinfo($basename, PATHINFO_EXTENSION);
            $wanted[] = [
                $path,
                $type,
                $slash === false ? '' : substr($path, 0, $slash),
                $basename,
                strpos($basename, '.') === false ? null : $extension,
                $size,
            ];
        }
        $this->assertSame($wanted, $actual, 'listing items');
        foreach ($items as $item) {
            $this->assertListingTimestamp($item, $since, $until);
        }
    }

    /**
     * Timestamp of a listing item made between $since and $until
     */
    protected function assertListingTimestamp(StorageItem $item, int $since, int $until): void
    {
        $timestamp = $item->getLastModified();
        if ($item->getType() === 'dir' && $this->deviates(Deviation::DIRECTORY_TIMESTAMPS_NONE)) {
            $this->assertNull($timestamp, 'no timestamp for directory ' . $item->getPath());
            return;
        }
        $this->assertIsInt($timestamp, 'timestamp of ' . $item->getPath());
        if ($this->deviates(Deviation::LISTING_TIMESTAMPS_MINUTES)) {
            $this->assertSame(0, $timestamp % 60, 'minute precision');
            $since -= $since % 60;
        }
        $this->assertGreaterThanOrEqual($since - 2, $timestamp, 'timestamp of ' . $item->getPath());
        $this->assertLessThanOrEqual($until + 2, $timestamp, 'timestamp of ' . $item->getPath());
    }

    /**
     * Runs $operation and asserts that it throws exactly an instance of $class (not a subclass
     * that would mean something else, e.g. ItemNotFoundException for StorageException)
     */
    protected function assertThrows(string $class, callable $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->assertSame($class, get_class($e), 'exception class, message: ' . $e->getMessage());
            return $e;
        }
        $this->fail("expected $class, nothing was thrown");
    }

    /**
     * The nested tree most tests start from
     */
    protected function seedNestedTree(): void
    {
        $this->seedFile('a.txt', 'A');
        $this->seedFile('d/x.txt', 'X');
        $this->seedFile('d/sub/y.txt', 'YY');
        $this->seedDir('d/empty');
        $this->seedFile('d-other/z.txt', 'Z');
        $this->seedFile('d.txt', 'DT');
    }

    protected const NESTED_TREE = [
        'a.txt' => 'A',
        'd/' => null,
        'd/x.txt' => 'X',
        'd/sub/' => null,
        'd/sub/y.txt' => 'YY',
        'd/empty/' => null,
        'd-other/' => null,
        'd-other/z.txt' => 'Z',
        'd.txt' => 'DT',
    ];

    // ------------------------------------------------------------------
    // exists
    // ------------------------------------------------------------------

    public function testExistsForFileDirectoryMissingAndRoot(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->exists('a.txt'));
        $this->assertTrue($this->storage->exists('/d/x.txt'));
        $this->assertTrue($this->storage->exists('d'));
        $this->assertTrue($this->storage->exists('d/'));
        $this->assertTrue($this->storage->exists('d/empty'));
        $this->assertTrue($this->storage->exists('d/sub'), 'a directory that exists only through its file');
        $this->assertFalse($this->storage->exists('nope'));
        $this->assertFalse($this->storage->exists('d/nope.txt'));
        $this->assertFalse($this->storage->exists('a.txt/below'));
        $this->assertFalse($this->storage->exists('/'), 'the root never counts as existing');
        $this->assertFalse($this->storage->exists(''));
    }

    // ------------------------------------------------------------------
    // itemInfo
    // ------------------------------------------------------------------

    public function testItemInfoOfFileDirectoryMissingAndRoot(): void
    {
        $this->seedNestedTree();

        $file = $this->storage->itemInfo('d/sub/y.txt');
        $this->assertInstanceOf(ItemInfo::class, $file);
        $this->assertSame(['file', 2, null], [$file->getType(), $file->getFileSize(), $file->getMimeType()]);

        foreach (['d', 'd/sub', 'd/empty', '/d/'] as $dir) {
            $info = $this->storage->itemInfo($dir);
            $this->assertSame(['dir', null, null], [$info->getType(), $info->getFileSize(), $info->getMimeType()], $dir);
        }

        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->itemInfo('nope');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->itemInfo('/');
        });
    }

    public function testItemInfoOfEmptyFile(): void
    {
        $this->seedFile('empty.txt');

        $info = $this->storage->itemInfo('empty.txt');

        $this->assertSame(['file', 0], [$info->getType(), $info->getFileSize()]);
    }

    // ------------------------------------------------------------------
    // listItems
    // ------------------------------------------------------------------

    public function testListShallowOfRootGivesDirectChildrenSorted(): void
    {
        $since = time();
        $this->seedNestedTree();
        $until = time();

        $items = $this->storage->listItems('/');

        $this->assertItems([
            ['a.txt', 'file', 1],
            ['d', 'dir', null],
            ['d-other', 'dir', null],
            ['d.txt', 'file', 2],
        ], $items, $since, $until);
    }

    public function testListShallowOfSubdirectoryHasPathsRelativeToTheRoot(): void
    {
        $since = time();
        $this->seedNestedTree();
        $until = time();

        foreach (['d', '/d', 'd/', '/d//'] as $form) {
            $this->assertItems([
                ['d/empty', 'dir', null],
                ['d/sub', 'dir', null],
                ['d/x.txt', 'file', 1],
            ], $this->storage->listItems($form), $since, $until);
        }
    }

    public function testListRecursiveGivesEveryItemIncludingEveryDirectory(): void
    {
        $since = time();
        $this->seedNestedTree();
        $this->seedFile('deep/er/est/f.txt', 'F');
        $until = time();

        $items = $this->storage->listItems('', true);

        $this->assertItems([
            // strcasecmp over the whole path: `-` < `.` < `/`
            ['a.txt', 'file', 1],
            ['d', 'dir', null],
            ['d-other', 'dir', null],
            ['d-other/z.txt', 'file', 1],
            ['d.txt', 'file', 2],
            ['d/empty', 'dir', null],
            ['d/sub', 'dir', null],
            ['d/sub/y.txt', 'file', 2],
            ['d/x.txt', 'file', 1],
            ['deep', 'dir', null],
            ['deep/er', 'dir', null],
            ['deep/er/est', 'dir', null],
            ['deep/er/est/f.txt', 'file', 1],
        ], $items, $since, $until);
    }

    public function testListRecursiveOfSubdirectory(): void
    {
        $since = time();
        $this->seedNestedTree();
        $until = time();

        $this->assertItems([
            ['d/empty', 'dir', null],
            ['d/sub', 'dir', null],
            ['d/sub/y.txt', 'file', 2],
            ['d/x.txt', 'file', 1],
        ], $this->storage->listItems('d', true), $since, $until);
    }

    public function testListOfEmptyDirectoryAndOfNestedEmptyDirectories(): void
    {
        $since = time();
        $this->seedDir('e');
        $this->storage->createDirectory('n/m/o');
        $until = time();

        $this->assertSame([], $this->storage->listItems('e'));
        $this->assertSame([], $this->storage->listItems('e', true));
        $this->assertItems([['n/m', 'dir', null]], $this->storage->listItems('n'), $since, $until);
        $this->assertItems([['n/m', 'dir', null], ['n/m/o', 'dir', null]], $this->storage->listItems('n', true), $since, $until);
        $this->assertItems([['e', 'dir', null], ['n', 'dir', null]], $this->storage->listItems(''), $since, $until);
    }

    public function testListOfMissingPathAndOfFileIsEmpty(): void
    {
        $this->seedNestedTree();

        $this->assertSame([], $this->storage->listItems('nope'));
        $this->assertSame([], $this->storage->listItems('nope', true));
        $this->assertSame([], $this->storage->listItems('a.txt'));
        $this->assertSame([], $this->storage->listItems('a.txt', true));
        $this->assertSame([], $this->storage->listItems('d/x.txt'));
    }

    public function testListOfEmptyRootIsEmpty(): void
    {
        $this->assertSame([], $this->storage->listItems('/'));
        $this->assertSame([], $this->storage->listItems('/', true));
    }

    public function testListIsSortedCaseInsensitively(): void
    {
        $since = time();
        $this->seedFile('b.txt', 'b');
        $this->seedFile('A.txt', 'a');
        $this->seedFile('C/x', 'c');
        $until = time();

        $this->assertItems([
            ['A.txt', 'file', 1],
            ['b.txt', 'file', 1],
            ['C', 'dir', null],
        ], $this->storage->listItems(''), $since, $until);
    }

    // ------------------------------------------------------------------
    // fileStat + fileStream
    // ------------------------------------------------------------------

    public function testFileStatAndFileStreamOfFile(): void
    {
        $this->seedFile('d/x.txt', 'hello');

        $info = $this->storage->fileStat('/d/x.txt');

        $this->assertSame(['file', 5, null], [$info->getType(), $info->getFileSize(), $info->getMimeType()]);
        $this->assertSame('hello', self::drain($this->storage->fileStream('d/x.txt')));
    }

    public function testFileStatOfDirectoryMissingAndRootIsNull(): void
    {
        $this->seedNestedTree();

        $this->assertNull($this->storage->fileStat('d'));
        $this->assertNull($this->storage->fileStat('d/empty'));
        $this->assertNull($this->storage->fileStat('d/sub'));
        $this->assertNull($this->storage->fileStat('nope'));
        $this->assertNull($this->storage->fileStat('a.txt/below'));
        $this->assertNull($this->storage->fileStat('/'));
    }

    public function testFileStreamOfMissingFileThrows(): void
    {
        $this->assertThrows(StorageException::class, function () {
            $this->storage->fileStream('nope.txt');
        });
    }

    public function testFileStreamOfDirectory(): void
    {
        $this->seedFile('d/x.txt', 'X');

        if ($this->deviates(Deviation::DIRECTORY_OPENS_AS_STREAM)) {
            $stream = @$this->storage->fileStream('d');
            $this->assertSame('', $stream === null ? '' : (string)@stream_get_contents($stream));
            return;
        }
        $this->assertThrows(StorageException::class, function () {
            $this->storage->fileStream('d');
        });
    }

    public function testFileStatAndFileStreamRefusePathsLeavingTheRoot(): void
    {
        $this->seedFile('x.txt', 'X');

        foreach (['../x.txt', 'd/../../x.txt', '/..'] as $path) {
            $this->assertThrows(InvalidPathException::class, function () use ($path) {
                $this->storage->fileStat($path);
            });
            $this->assertThrows(InvalidPathException::class, function () use ($path) {
                $this->storage->fileStream($path);
            });
        }
    }

    // ------------------------------------------------------------------
    // openFile
    // ------------------------------------------------------------------

    public function testOpenFile(): void
    {
        $this->seedFile('d/x.txt', 'hello');

        $this->assertSame('hello', self::drain($this->storage->openFile('d/x.txt')));
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->openFile('nope.txt');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->openFile('/');
        });
    }

    public function testOpenFileOfDirectory(): void
    {
        $this->seedFile('d/x.txt', 'X');

        if ($this->deviates(Deviation::DIRECTORY_OPENS_AS_STREAM)) {
            $stream = @$this->storage->openFile('d');
            $this->assertSame('', $stream === null ? '' : (string)@stream_get_contents($stream));
            return;
        }
        $this->assertThrows(StorageException::class, function () {
            $this->storage->openFile('d');
        });
    }

    // ------------------------------------------------------------------
    // createDirectory
    // ------------------------------------------------------------------

    public function testCreateDirectoryCreatesMissingParents(): void
    {
        $this->assertTrue($this->storage->createDirectory('a/b/c'));

        $this->assertTree(['a/' => null, 'a/b/' => null, 'a/b/c/' => null]);
        $this->assertTrue($this->storage->exists('a/b/c'));
        $this->assertSame('dir', $this->storage->itemInfo('a/b')->getType());
    }

    public function testCreateDirectoryOfExistingDirectorySucceedsAndKeepsContent(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->createDirectory('d'));
        $this->assertTrue($this->storage->createDirectory('d/empty'));
        $this->assertTrue($this->storage->createDirectory('d/sub'));

        $this->assertTree(self::NESTED_TREE);
    }

    public function testCreateDirectoryOverFileOrBelowFileFails(): void
    {
        $this->seedFile('f.txt', 'F');

        $this->assertFalse($this->storage->createDirectory('f.txt'));
        $this->assertFalse($this->storage->createDirectory('f.txt/sub'));
        $this->assertFalse($this->storage->createDirectory('f.txt/sub/deeper'));

        $this->assertTree(['f.txt' => 'F']);
        $this->assertSame('file', $this->storage->itemInfo('f.txt')->getType());
    }

    public function testCreateDirectoryOfRootSucceedsWithoutEffect(): void
    {
        $this->assertTrue($this->storage->createDirectory('/'));
        $this->assertTree([]);
    }

    // ------------------------------------------------------------------
    // deleteFile
    // ------------------------------------------------------------------

    public function testDeleteFileKeepsSiblingsAndItsDirectory(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->deleteFile('d/x.txt'));
        $this->assertTrue($this->storage->deleteFile('d/sub/y.txt'));
        $this->assertTrue($this->storage->deleteFile('d-other/z.txt'));

        $expected = self::NESTED_TREE;
        unset($expected['d/x.txt'], $expected['d/sub/y.txt'], $expected['d-other/z.txt']);
        $this->assertTree($expected, 'the directories of the deleted files stay, as empty directories');
        $this->assertTrue($this->storage->exists('d/sub'));
        $this->assertSame('dir', $this->storage->itemInfo('d-other')->getType());
        $this->assertSame([], $this->storage->listItems('d/sub'));
    }

    public function testDeleteFileOfMissingPathThrows(): void
    {
        $this->seedFile('x.txt', 'X');

        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->deleteFile('nope.txt');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->deleteFile('/');
        });
        $this->assertTree(['x.txt' => 'X']);
    }

    public function testDeleteFileOfDirectoryFailsAndKeepsIt(): void
    {
        $this->seedNestedTree();

        $this->assertFalse($this->storage->deleteFile('d'));
        $this->assertFalse($this->storage->deleteFile('d/empty'));
        $this->assertFalse($this->storage->deleteFile('d/sub'));

        $this->assertTree(self::NESTED_TREE);
    }

    // ------------------------------------------------------------------
    // deleteDirectory
    // ------------------------------------------------------------------

    public function testDeleteDirectoryWithChildrenKeepsSiblingsWithTheSamePrefix(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->deleteDirectory('d'));

        $this->assertTree(['a.txt' => 'A', 'd-other/' => null, 'd-other/z.txt' => 'Z', 'd.txt' => 'DT']);
        $this->assertFalse($this->storage->exists('d'));
    }

    public function testDeleteDirectoryEmptyAndNestedKeepsItsParent(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->deleteDirectory('d/empty'));
        $this->assertTrue($this->storage->deleteDirectory('d/sub'));

        $this->assertTree(['a.txt' => 'A', 'd/' => null, 'd/x.txt' => 'X', 'd-other/' => null, 'd-other/z.txt' => 'Z', 'd.txt' => 'DT']);
    }

    public function testDeleteDirectoryOfOnlyChildKeepsTheParentAsEmptyDirectory(): void
    {
        $this->storage->createDirectory('p/q/r');

        $this->assertTrue($this->storage->deleteDirectory('p/q/r'));

        $this->assertTree(['p/' => null, 'p/q/' => null]);
        $this->assertSame('dir', $this->storage->itemInfo('p/q')->getType());
    }

    public function testDeleteDirectoryOfMissingPathOrFileFailsAndChangesNothing(): void
    {
        $this->seedNestedTree();

        $this->assertFalse($this->storage->deleteDirectory('nope'));
        $this->assertFalse($this->storage->deleteDirectory('d/nope'));
        $this->assertFalse($this->storage->deleteDirectory('a.txt'));
        $this->assertFalse($this->storage->deleteDirectory('d/x.txt'));

        $this->assertTree(self::NESTED_TREE);
    }

    public function testDeleteDirectoryOfRootIsRefused(): void
    {
        $this->seedNestedTree();

        foreach (['', '/', '.', 'd/..'] as $root) {
            $this->assertThrows(InvalidPathException::class, function () use ($root) {
                $this->storage->deleteDirectory($root);
            });
        }
        $this->assertTree(self::NESTED_TREE);
    }

    // ------------------------------------------------------------------
    // move: files
    // ------------------------------------------------------------------

    public function testMoveFileToMissingTarget(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->move('d/x.txt', 'moved.txt'));

        $expected = self::NESTED_TREE;
        unset($expected['d/x.txt']);
        $this->assertTree($expected + ['moved.txt' => 'X']);
    }

    public function testMoveLastFileOutOfDirectoryKeepsTheDirectory(): void
    {
        $this->seedFile('only/x.txt', 'X');

        $this->assertTrue($this->storage->move('only/x.txt', 'x.txt'));

        $this->assertTree(['only/' => null, 'x.txt' => 'X']);
    }

    public function testMoveFileIntoMissingParentCreatesIt(): void
    {
        $this->seedFile('x.txt', 'X');

        $this->assertTrue($this->storage->move('x.txt', 'n/m/x.txt'));

        $this->assertTree(['n/' => null, 'n/m/' => null, 'n/m/x.txt' => 'X']);
    }

    public function testMoveFileOntoExistingFileDirectoryOrItselfIsRefused(): void
    {
        $this->seedNestedTree();

        foreach (['d.txt', 'd/x.txt', 'd', 'd/sub', 'd/empty', 'a.txt', '/a.txt/', '/'] as $target) {
            $this->assertThrows(ItemExistsException::class, function () use ($target) {
                $this->storage->move('a.txt', $target);
            });
        }
        $this->assertTree(self::NESTED_TREE);
    }

    public function testMoveOfMissingSourceThrowsBeforeTheTargetIsLookedAt(): void
    {
        $this->seedNestedTree();

        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->move('nope.txt', 'a.txt');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->move('nope.txt', 'fresh.txt');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->move('/', 'fresh');
        });
        $this->assertTree(self::NESTED_TREE);
    }

    public function testMoveFileBelowAFileFails(): void
    {
        $this->seedFile('f.txt', 'F');
        $this->seedFile('x.txt', 'X');

        $this->assertThrows(StorageException::class, function () {
            $this->storage->move('x.txt', 'f.txt/x.txt');
        });
        $this->assertTree(['f.txt' => 'F', 'x.txt' => 'X']);
    }

    // ------------------------------------------------------------------
    // move: directories
    // ------------------------------------------------------------------

    public function testMoveDirectoryWithNestedChildrenKeepsSiblingsWithTheSamePrefix(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->move('d', 'e'));

        $this->assertTree([
            'a.txt' => 'A',
            'e/' => null,
            'e/x.txt' => 'X',
            'e/sub/' => null,
            'e/sub/y.txt' => 'YY',
            'e/empty/' => null,
            'd-other/' => null,
            'd-other/z.txt' => 'Z',
            'd.txt' => 'DT',
        ]);
        $this->assertFalse($this->storage->exists('d'));
        $this->assertSame('dir', $this->storage->itemInfo('e/empty')->getType());
    }

    public function testMoveEmptyDirectory(): void
    {
        $this->seedDir('e1');

        $this->assertTrue($this->storage->move('e1', 'e2'));

        $this->assertTree(['e2/' => null]);
    }

    public function testMoveDirectoryIntoMissingParentAndOutOfItsParent(): void
    {
        $this->seedFile('p/d/x.txt', 'X');
        $this->seedDir('p/d/empty');

        $this->assertTrue($this->storage->move('p/d', 'n/m/d'));

        $this->assertTree(['n/' => null, 'n/m/' => null, 'n/m/d/' => null, 'n/m/d/x.txt' => 'X', 'n/m/d/empty/' => null, 'p/' => null]);
    }

    public function testMoveDirectoryOntoExistingFileDirectoryOrItselfIsRefused(): void
    {
        $this->seedNestedTree();

        foreach (['d.txt', 'd-other', 'd', 'd/', '/'] as $target) {
            $this->assertThrows(ItemExistsException::class, function () use ($target) {
                $this->storage->move('d', $target);
            });
        }
        $this->assertTree(self::NESTED_TREE);
    }

    public function testMoveDirectoryIntoItselfFailsAndChangesNothing(): void
    {
        $this->seedNestedTree();

        $this->assertThrows(StorageException::class, function () {
            $this->storage->move('d', 'd/sub/inner');
        });
        $this->assertThrows(StorageException::class, function () {
            $this->storage->move('d', 'd/new');
        });
        $this->assertTree(self::NESTED_TREE);
    }

    public function testMoveDirectoryBelowAFileFails(): void
    {
        $this->seedNestedTree();

        $this->assertThrows(StorageException::class, function () {
            $this->storage->move('d', 'a.txt/d');
        });
        $this->assertTree(self::NESTED_TREE);
    }

    // ------------------------------------------------------------------
    // copyFile
    // ------------------------------------------------------------------

    public function testCopyFileToMissingTargetAndIntoMissingParent(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->copyFile('d/x.txt', 'copy.txt'));
        $this->assertTrue($this->storage->copyFile('d/x.txt', 'n/m/copy.txt'));

        $this->assertTree(self::NESTED_TREE + ['copy.txt' => 'X', 'n/' => null, 'n/m/' => null, 'n/m/copy.txt' => 'X']);
    }

    public function testCopyFileOntoExistingFileDirectoryOrItselfIsRefused(): void
    {
        $this->seedNestedTree();

        foreach (['d.txt', 'd/x.txt', 'd', 'd/sub', 'd/empty', 'a.txt', '/'] as $target) {
            $this->assertThrows(ItemExistsException::class, function () use ($target) {
                $this->storage->copyFile('a.txt', $target);
            });
        }
        $this->assertTree(self::NESTED_TREE);
    }

    public function testCopyFileOfMissingSourceThrows(): void
    {
        $this->seedNestedTree();

        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->copyFile('nope.txt', 'a.txt');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->copyFile('nope.txt', 'fresh.txt');
        });
        $this->assertTree(self::NESTED_TREE);
    }

    public function testCopyFileOfDirectoryFailsAndCreatesNothing(): void
    {
        $this->seedNestedTree();

        $this->assertThrows(StorageException::class, function () {
            $this->storage->copyFile('d', 'e');
        });
        $this->assertThrows(StorageException::class, function () {
            $this->storage->copyFile('d/empty', 'e');
        });
        $this->assertTree(self::NESTED_TREE);
    }

    public function testCopyFileBelowAFileFails(): void
    {
        $this->seedFile('f.txt', 'F');
        $this->seedFile('x.txt', 'X');

        $this->assertThrows(StorageException::class, function () {
            $this->storage->copyFile('x.txt', 'f.txt/x.txt');
        });
        $this->assertTree(['f.txt' => 'F', 'x.txt' => 'X']);
    }

    // ------------------------------------------------------------------
    // writeFile
    // ------------------------------------------------------------------

    public function testWriteFileNewAndIntoMissingParents(): void
    {
        $this->assertTrue($this->storage->writeFile('new.txt', self::stream('N')));
        $this->assertTrue($this->storage->writeFile('n/m/deep.txt', self::stream('D')));

        $this->assertTree(['new.txt' => 'N', 'n/' => null, 'n/m/' => null, 'n/m/deep.txt' => 'D']);
    }

    public function testWriteFileRewindsTheStreamAndKeepsItOpen(): void
    {
        $stream = self::stream('content');
        fseek($stream, 3);

        $this->assertTrue($this->storage->writeFile('x.txt', $stream));

        $this->assertIsResource($stream);
        fclose($stream);
        $this->assertTree(['x.txt' => 'content']);
    }

    public function testWriteFileOntoExistingFileOrDirectoryIsRefused(): void
    {
        $this->seedNestedTree();

        foreach (['a.txt', 'd', 'd/sub', 'd/empty', '/d/x.txt', '/'] as $target) {
            $this->assertThrows(ItemExistsException::class, function () use ($target) {
                $this->storage->writeFile($target, self::stream('NEW'));
            });
        }
        $this->assertTree(self::NESTED_TREE);
    }

    public function testWriteFileBelowAFileFails(): void
    {
        $this->seedFile('f.txt', 'F');

        $this->assertThrows(StorageException::class, function () {
            $this->storage->writeFile('f.txt/child.txt', self::stream('C'));
        });
        $this->assertThrows(StorageException::class, function () {
            $this->storage->writeFile('f.txt/sub/child.txt', self::stream('C'));
        });
        $this->assertTree(['f.txt' => 'F']);
    }

    public function testWriteFileWithoutStreamIsRefused(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function () {
            $this->storage->writeFile('x.txt', 'not a stream');
        });
        $this->assertTree([]);
    }

    // ------------------------------------------------------------------
    // replaceFile
    // ------------------------------------------------------------------

    public function testReplaceFileReplacesExistingFileAndCreatesMissingOne(): void
    {
        $this->seedFile('d/x.txt', 'old content');

        $this->assertTrue($this->storage->replaceFile('d/x.txt', self::stream('new')));
        $this->assertTrue($this->storage->replaceFile('n/fresh.txt', self::stream('fresh')));

        $this->assertTree(['d/' => null, 'd/x.txt' => 'new', 'n/' => null, 'n/fresh.txt' => 'fresh']);
    }

    public function testReplaceFileRefusesDirectoriesAndTheRoot(): void
    {
        $this->seedNestedTree();

        foreach (['d', 'd/sub', 'd/empty', '/'] as $target) {
            $this->assertThrows(ItemExistsException::class, function () use ($target) {
                $this->storage->replaceFile($target, self::stream('NEW'));
            });
        }
        $this->assertTree(self::NESTED_TREE);
    }

    public function testReplaceFileBelowAFileFails(): void
    {
        $this->seedFile('f.txt', 'F');

        $this->assertThrows(StorageException::class, function () {
            $this->storage->replaceFile('f.txt/child.txt', self::stream('C'));
        });
        $this->assertTree(['f.txt' => 'F']);
    }

    // ------------------------------------------------------------------
    // lastModified
    // ------------------------------------------------------------------

    public function testLastModifiedOfFileHasSecondPrecision(): void
    {
        $since = time();
        $this->seedFile('d/x.txt', 'X');
        $until = time();

        $time = $this->storage->lastModified('d/x.txt');

        $this->assertIsInt($time);
        $this->assertGreaterThanOrEqual($since - 2, $time);
        $this->assertLessThanOrEqual($until + 2, $time);
    }

    public function testLastModifiedOfDirectory(): void
    {
        $since = time();
        $this->seedFile('d/x.txt', 'X');
        $this->seedDir('e');
        $until = time();

        foreach (['d', 'e'] as $dir) {
            $time = $this->storage->lastModified($dir);
            if ($this->deviates(Deviation::DIRECTORY_TIMESTAMPS_NONE) || $this->deviates(Deviation::DIRECTORY_LAST_MODIFIED_NONE)) {
                $this->assertNull($time, $dir);
                continue;
            }
            $this->assertIsInt($time, $dir);
            $this->assertGreaterThanOrEqual($since - 2, $time, $dir);
            $this->assertLessThanOrEqual($until + 2, $time, $dir);
        }
    }

    public function testLastModifiedOfMissingPathAndRootThrows(): void
    {
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->lastModified('nope.txt');
        });
        $this->assertThrows(ItemNotFoundException::class, function () {
            $this->storage->lastModified('/');
        });
    }

    // ------------------------------------------------------------------
    // content and names
    // ------------------------------------------------------------------

    public function testLargeFileRoundTripKeepsEveryByte(): void
    {
        $content = self::blob(self::LARGE_SIZE);
        $hash = sha1($content);
        $source = self::stream($content);

        $this->assertTrue($this->storage->writeFile('big/large.bin', $source));
        fclose($source);

        $this->assertSame(['file', self::LARGE_SIZE], [
            $this->storage->fileStat('big/large.bin')->getType(),
            $this->storage->fileStat('big/large.bin')->getFileSize(),
        ]);
        $this->assertSame($hash, sha1(self::drain($this->storage->fileStream('big/large.bin'))));
        $this->assertSame($hash, sha1(self::drain($this->storage->openFile('big/large.bin'))));
        $this->assertSame(self::LARGE_SIZE, $this->storage->listItems('big')[0]->getFileSize());
        $this->assertSame($hash, sha1($this->raw->read('big/large.bin')));
    }

    public function testBinaryContentWithNullBytesAndNewlines(): void
    {
        $content = "\0\r\n\x01\xff line\n\0";

        $this->assertTrue($this->storage->writeFile('bin.dat', self::stream($content)));

        $this->assertSame($content, self::drain($this->storage->fileStream('bin.dat')));
        $this->assertSame(strlen($content), $this->storage->fileStat('bin.dat')->getFileSize());
    }

    public function specialNameProvider(): array
    {
        return [
            'space' => ['with space.txt'],
            'umlauts' => ["umlaut-\u{e4}\u{f6}\u{fc}\u{df}.txt"],
            'hash' => ['hash#tag.txt'],
            'percent' => ['percent%20sign%.txt'],
            'plus' => ['plus+sign.txt'],
            'all' => ["a #%+\u{e4}.txt"],
        ];
    }

    /**
     * @dataProvider specialNameProvider
     */
    public function testSpecialCharactersInFileAndDirectoryNames(string $name): void
    {
        $dir = 'dir ' . pathinfo($name, PATHINFO_FILENAME);
        $since = time();

        $this->assertTrue($this->storage->createDirectory($dir));
        $this->assertTrue($this->storage->writeFile("$dir/$name", self::stream('content')));
        $until = time();

        $this->assertTrue($this->storage->exists("$dir/$name"));
        $this->assertSame(7, $this->storage->itemInfo("$dir/$name")->getFileSize());
        $this->assertSame('dir', $this->storage->itemInfo($dir)->getType());
        $this->assertItems([["$dir/$name", 'file', 7]], $this->storage->listItems($dir), $since, $until);
        $this->assertItems([[$dir, 'dir', null], ["$dir/$name", 'file', 7]], $this->storage->listItems('', true), $since, $until);
        $this->assertSame('content', self::drain($this->storage->fileStream("$dir/$name")));
        $this->assertTree([$dir . '/' => null, "$dir/$name" => 'content']);

        $this->assertTrue($this->storage->copyFile("$dir/$name", "$dir/copy $name"));
        $this->assertTrue($this->storage->move("$dir/$name", "moved $name"));
        $this->assertTrue($this->storage->move($dir, "$dir renamed"));
        $this->assertTree(["$dir renamed/" => null, "$dir renamed/copy $name" => 'content', "moved $name" => 'content']);

        $this->assertTrue($this->storage->deleteFile("moved $name"));
        $this->assertTrue($this->storage->deleteDirectory("$dir renamed"));
        $this->assertTree([]);
    }

    // ------------------------------------------------------------------
    // refused paths
    // ------------------------------------------------------------------

    public function refusedPathProvider(): array
    {
        return [
            'parent of root' => ['../x.txt'],
            'inner traversal' => ['d/../../x.txt'],
            'only dots' => ['/..'],
            'traversal after normalisation' => ['d/./../../d'],
            'NUL' => ["d/x\0.txt"],
            'newline' => ["d/x\n.txt"],
            'tab' => ["d/x\t.txt"],
            'DEL' => ["d/x\x7f.txt"],
        ];
    }

    /**
     * Every method refuses the path with InvalidPathException before it calls the library at all
     *
     * @dataProvider refusedPathProvider
     */
    public function testRefusedPathsMakeNoLibraryCall(string $path): void
    {
        $this->seedNestedTree();
        $counting = new CountingOperator($this->raw);
        $storage = new Storage(['filesystem' => $counting]);

        $operations = [
            'exists' => function () use ($storage, $path) { $storage->exists($path); },
            'itemInfo' => function () use ($storage, $path) { $storage->itemInfo($path); },
            'fileStat' => function () use ($storage, $path) { $storage->fileStat($path); },
            'fileStream' => function () use ($storage, $path) { $storage->fileStream($path); },
            'openFile' => function () use ($storage, $path) { $storage->openFile($path); },
            'listItems' => function () use ($storage, $path) { $storage->listItems($path); },
            'listItems recursive' => function () use ($storage, $path) { $storage->listItems($path, true); },
            'lastModified' => function () use ($storage, $path) { $storage->lastModified($path); },
            'createDirectory' => function () use ($storage, $path) { $storage->createDirectory($path); },
            'deleteFile' => function () use ($storage, $path) { $storage->deleteFile($path); },
            'deleteDirectory' => function () use ($storage, $path) { $storage->deleteDirectory($path); },
            'move from' => function () use ($storage, $path) { $storage->move($path, 'fresh'); },
            'move to' => function () use ($storage, $path) { $storage->move('a.txt', $path); },
            'copyFile from' => function () use ($storage, $path) { $storage->copyFile($path, 'fresh'); },
            'copyFile to' => function () use ($storage, $path) { $storage->copyFile('a.txt', $path); },
            'writeFile' => function () use ($storage, $path) { $storage->writeFile($path, self::stream('x')); },
            'replaceFile' => function () use ($storage, $path) { $storage->replaceFile($path, self::stream('x')); },
            'normalizePath' => function () use ($storage, $path) { $storage->normalizePath($path); },
        ];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail("$name accepted the path");
            } catch (InvalidPathException $e) {
                // expected
            }
            $this->assertSame([], $counting->calls, "$name called the library");
        }
        $this->assertTree(self::NESTED_TREE);
    }
}
