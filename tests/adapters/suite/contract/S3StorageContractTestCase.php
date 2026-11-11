<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

use Aws\S3\S3Client;
use hrzg\filefly\components\Storage;
use hrzg\filefly\components\storage\InvalidPathException;
use hrzg\filefly\components\storage\ItemExistsException;
use hrzg\filefly\components\storage\StorageException;
use hrzg\filefly\tests\adapters\suite\support\Backends;
use hrzg\filefly\tests\adapters\suite\support\CountingAwsS3Filesystem;
use hrzg\filefly\tests\adapters\suite\support\TestRoot;

/**
 * The storage contract on an S3 server, plus what only S3 has: raw object keys (directory
 * markers, implicit directories), the requests per facade method (pinned), and the isolation of
 * the component's `prefix`.
 *
 * Each test gets its own prefix (TestRoot::s3()).
 */
abstract class S3StorageContractTestCase extends StorageContractTestCase
{
    /**
     * @var string prefix of the component under test, without trailing slash
     */
    protected $prefix;

    /**
     * @var S3Client|null
     */
    private $client;

    /**
     * @var TestRoot|null
     */
    private $root;

    /**
     * Endpoint of the server inside the compose network
     */
    abstract protected function endpoint(): string;

    protected function deviations(): array
    {
        return [Deviation::DIRECTORY_TIMESTAMPS_NONE];
    }

    protected function createTestRoot(): array
    {
        $this->root = TestRoot::s3($this->endpoint());
        $this->prefix = $this->root->prefix;
        $this->client = $this->root->client;
        return $this->root->config;
    }

    protected function removeTestRoot(): void
    {
        if ($this->root !== null) {
            $this->root->remove();
        }
        $this->root = null;
        $this->client = null;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Object keys below the test prefix, relative to it, sorted
     *
     * @return string[]
     */
    protected function rawKeys(?string $prefix = null): array
    {
        $prefix = $prefix ?? $this->prefix . '/';
        $keys = [];
        $pages = $this->client->getPaginator('ListObjectsV2', ['Bucket' => Backends::env('S3_BUCKET'), 'Prefix' => $prefix]);
        foreach ($pages as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $keys[] = substr($object['Key'], strlen($prefix));
            }
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    protected function putRaw(string $key, string $body): void
    {
        $this->client->putObject(['Bucket' => Backends::env('S3_BUCKET'), 'Key' => $key, 'Body' => $body]);
    }

    protected function getRaw(string $key): string
    {
        return (string)$this->client->getObject(['Bucket' => Backends::env('S3_BUCKET'), 'Key' => $key])['Body'];
    }

    /**
     * S3 commands sent by $operation
     */
    protected function requests(callable $operation, &$result = null, ?\Throwable &$exception = null): array
    {
        return CountingAwsS3Filesystem::record($operation, $result, $exception);
    }

    // ------------------------------------------------------------------
    // directories on S3
    // ------------------------------------------------------------------

    public function testCreateDirectoryWritesOneMarkerForTheDeepestLevel(): void
    {
        $this->assertTrue($this->storage->createDirectory('a/b/c'));

        $this->assertSame(['a/b/c/'], $this->rawKeys());
    }

    public function testImplicitDirectoryIsADirectoryEverywhere(): void
    {
        $this->putRaw($this->prefix . '/imp/sub/x.txt', 'X');
        $since = time();

        $this->assertTrue($this->storage->exists('imp'));
        $this->assertTrue($this->storage->exists('imp/sub'));
        $this->assertSame('dir', $this->storage->itemInfo('imp')->getType());
        $this->assertSame('dir', $this->storage->itemInfo('imp/sub')->getType());
        $this->assertItems([['imp', 'dir', null]], $this->storage->listItems(''), $since - 5, time());
        $this->assertItems([
            ['imp', 'dir', null],
            ['imp/sub', 'dir', null],
            ['imp/sub/x.txt', 'file', 1],
        ], $this->storage->listItems('', true), $since - 5, time());
        $this->assertThrows(ItemExistsException::class, function () {
            $this->storage->writeFile('imp', self::stream('F'));
        });
        $this->assertTrue($this->storage->createDirectory('imp'), 'an existing directory, nothing is written');
        $this->assertSame(['imp/sub/x.txt'], $this->rawKeys());
    }

    public function testDeletingTheLastFileOfAnImplicitDirectoryLeavesAMarker(): void
    {
        $this->putRaw($this->prefix . '/imp/sub/x.txt', 'X');

        $this->assertTrue($this->storage->deleteFile('imp/sub/x.txt'));

        $this->assertSame(['imp/sub/'], $this->rawKeys());
        $this->assertTrue($this->storage->exists('imp/sub'));
        $this->assertSame([], $this->storage->listItems('imp/sub'));
    }

    public function testFileAndDirectoryOfTheSameNameMadeOutsideTheModuleAreNeverAddedTo(): void
    {
        // a bucket written by other tools can hold the key `f` and keys below `f/`
        $this->putRaw($this->prefix . '/f', 'FILE');
        $this->putRaw($this->prefix . '/f/x.txt', 'X');

        $this->assertSame('file', $this->storage->itemInfo('f')->getType());
        $this->assertSame(4, $this->storage->fileStat('f')->getFileSize());
        foreach (['f', 'f/x.txt'] as $target) {
            $this->assertThrows(ItemExistsException::class, function () use ($target) {
                $this->storage->writeFile($target, self::stream('NEW'));
            });
        }
        $this->assertThrows(StorageException::class, function () {
            $this->storage->writeFile('f/new.txt', self::stream('NEW'));
        });
        $this->assertFalse($this->storage->createDirectory('f/new'));
        $this->assertSame(['f', 'f/x.txt'], $this->rawKeys());
        $this->assertSame('FILE', $this->getRaw($this->prefix . '/f'));
    }

    public function testMoveOfDirectoryCopiesEveryObjectIncludingMarkersAndRemovesTheSource(): void
    {
        $this->seedNestedTree();

        $this->assertTrue($this->storage->move('d', 'e'));

        $this->assertSame(['a.txt', 'd-other/z.txt', 'd.txt', 'e/', 'e/empty/', 'e/sub/y.txt', 'e/x.txt'], $this->rawKeys());
    }

    // ------------------------------------------------------------------
    // requests per method (pinned, see the table in Storage and UPGRADE.md)
    // ------------------------------------------------------------------

    public function testRequestsOfFileStatAndFileStream(): void
    {
        $this->seedFile('d/x.txt', 'hello');

        $this->assertSame(['HeadObject', 'GetObject'], $this->requests(function () {
            $this->storage->fileStat('d/x.txt');
            fclose($this->storage->fileStream('d/x.txt'));
        }));
        $this->assertSame(['HeadObject'], $this->requests(function () {
            $this->storage->fileStat('nope.txt');
        }));
    }

    public function testRequestsOfExists(): void
    {
        $this->seedFile('d/x.txt', 'hello');

        $this->assertSame(['HeadObject'], $this->requests(function () {
            $this->storage->exists('d/x.txt');
        }));
        $this->assertSame(['HeadObject', 'ListObjectsV2'], $this->requests(function () {
            $this->storage->exists('d');
        }));
        $this->assertSame(['HeadObject', 'ListObjectsV2'], $this->requests(function () {
            $this->storage->exists('nope');
        }));
    }

    public function testRequestsOfListing(): void
    {
        $this->seedNestedTree();

        $this->assertSame(['ListObjectsV2'], $this->requests(function () {
            $this->storage->listItems('d');
        }));
        $this->assertSame(['ListObjectsV2'], $this->requests(function () {
            $this->storage->listItems('', true);
        }));
    }

    public function testRequestsOfWriteFile(): void
    {
        $this->seedDir('d');

        $this->assertSame(['HeadObject', 'ListObjectsV2', 'PutObject'], $this->requests(function () {
            $this->storage->writeFile('x.txt', self::stream('X'));
        }), 'in the root');
        $this->assertSame(['HeadObject', 'ListObjectsV2', 'HeadObject', 'PutObject'], $this->requests(function () {
            $this->storage->writeFile('d/x.txt', self::stream('X'));
        }), 'one level down: one HeadObject per parent level');
    }

    public function testRequestsOfCopyFile(): void
    {
        $this->seedFile('x.txt', 'X');

        $this->assertSame(['HeadObject', 'HeadObject', 'ListObjectsV2', 'HeadObject', 'CopyObject'], $this->requests(function () {
            $this->storage->copyFile('x.txt', 'y.txt');
        }));
    }

    public function testRequestsOfMoveOfFile(): void
    {
        $this->seedFile('x.txt', 'X');

        $this->assertSame(['HeadObject', 'HeadObject', 'ListObjectsV2', 'HeadObject', 'CopyObject', 'DeleteObject'], $this->requests(function () {
            $this->storage->move('x.txt', 'y.txt');
        }));
    }

    /**
     * @dataProvider refusedPathProvider
     */
    public function testRefusedPathsMakeNoS3Request(string $path): void
    {
        $this->seedFile('a.txt', 'A');

        $requests = $this->requests(function () use ($path) {
            foreach ([
                function () use ($path) { $this->storage->fileStat($path); },
                function () use ($path) { $this->storage->fileStream($path); },
                function () use ($path) { $this->storage->exists($path); },
                function () use ($path) { $this->storage->listItems($path, true); },
                function () use ($path) { $this->storage->deleteDirectory($path); },
                function () use ($path) { $this->storage->move('a.txt', $path); },
                function () use ($path) { $this->storage->writeFile($path, self::stream('x')); },
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('the path was accepted');
                } catch (InvalidPathException $e) {
                    // expected
                }
            }
        });

        $this->assertSame([], $requests);
    }

    // ------------------------------------------------------------------
    // prefix isolation
    // ------------------------------------------------------------------

    public function testObjectsOutsideThePrefixCannotBeReached(): void
    {
        $base = Backends::S3_BASE_PREFIX . '/';
        $unique = substr($this->prefix, strlen($base));
        $outside = [
            $this->prefix . '.txt' => 'SIBLING KEY',
            $this->prefix . '-outside/secret.txt' => 'SIBLING DIR',
            $this->prefix . '-outside/' => '',
        ];
        foreach ($outside as $key => $body) {
            $this->putRaw($key, $body);
        }
        $this->seedFile('in.txt', 'IN');
        $this->seedFile('d/x.txt', 'X');

        $forms = [
            '../' . $unique . '.txt',
            '/../' . $unique . '-outside/secret.txt',
            'd/../../' . $unique . '-outside/secret.txt',
            '..',
            '../..',
        ];
        foreach ($forms as $form) {
            foreach ([
                'fileStat' => function () use ($form) { return $this->storage->fileStat($form); },
                'fileStream' => function () use ($form) { return $this->storage->fileStream($form); },
                'exists' => function () use ($form) { return $this->storage->exists($form); },
                'listItems' => function () use ($form) { return $this->storage->listItems($form, true); },
                'deleteFile' => function () use ($form) { return $this->storage->deleteFile($form); },
                'deleteDirectory' => function () use ($form) { return $this->storage->deleteDirectory($form); },
                'move' => function () use ($form) { return $this->storage->move($form, 'stolen.txt'); },
                'copyFile' => function () use ($form) { return $this->storage->copyFile($form, 'stolen.txt'); },
            ] as $name => $operation) {
                $requests = $this->requests($operation, $result, $exception);
                $this->assertInstanceOf(InvalidPathException::class, $exception, "$name($form)");
                $this->assertSame([], $requests, "$name($form)");
            }
        }
        // names that only look like a way out stay inside the prefix
        foreach ([$unique . '.txt', $unique . '-outside/secret.txt', '..%2F' . $unique . '.txt', '%2e%2e/' . $unique . '.txt'] as $name) {
            $this->assertNull($this->storage->fileStat($name), $name);
            $this->assertFalse($this->storage->exists($name), $name);
        }
        $this->assertTrue($this->storage->deleteDirectory('d'));
        $this->assertTrue($this->storage->deleteFile('in.txt'));

        $this->assertSame([], $this->storage->listItems('', true));
        $this->assertSame([], $this->rawKeys());
        foreach ($outside as $key => $body) {
            $this->assertSame($body, $this->getRaw($key), $key);
        }
    }
}
