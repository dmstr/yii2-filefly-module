<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\api;

use Aws\S3\S3Client;
use hrzg\filefly\tests\adapters\suite\support\Backends;
use hrzg\filefly\tests\adapters\suite\support\CountingAwsS3Filesystem;
use hrzg\filefly\tests\adapters\suite\support\RawTree;
use hrzg\filefly\tests\adapters\suite\support\TestRoot;
use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;
use League\Flysystem\FilesystemOperator;
use yii\web\NotFoundHttpException;

/**
 * The HTTP API end to end on S3: the module boots exactly as in tests/support/ApiTestCase, only
 * the filesystem component `fsTest` is an AwsS3Filesystem (with request counting) on the prefix
 * `filefly-contract/<unique>`. The harness's disk helpers address the local disk, so seeding and
 * assertions go through a second component on the same prefix (`raw`) or the S3 client.
 *
 * Covered: `stream` and `download` (existing file with status, body, headers and exactly one
 * HeadObject plus one GetObject; missing file; directory; paths leaving the prefix answered with
 * 404 and no request at all; objects outside the prefix unreachable by any path form), and
 * `list`, `createFolder`, `upload` (also onto an existing file), `rename` and `move` of a folder
 * with children, `remove` of a folder.
 */
abstract class S3ApiTestCase extends ApiTestCase
{
    private const SECRET = 'SECRET-OUTSIDE-THE-PREFIX-4b1e';

    /**
     * @var string|null prefix of fsTest, without trailing slash
     */
    private $prefix;

    /**
     * @var TestRoot|null
     */
    private $root;

    /**
     * @var FilesystemOperator|null operator on the same prefix, for seeding and assertions
     */
    protected $raw;

    /**
     * @var S3Client|null
     */
    private $client;

    abstract protected function endpoint(): string;

    protected function setUp(): void
    {
        $this->root = TestRoot::s3($this->endpoint());
        $this->prefix = $this->root->prefix;
        $this->client = $this->root->client;
        parent::setUp();
        $this->raw = \Yii::createObject($this->root->config)->getFilesystem();
        ini_set('zend.exception_ignore_args', '1');
    }

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $this->root->remove();
        }
        $this->root = null;
        $this->client = null;
        $this->raw = null;
        parent::tearDown();
    }

    protected function filesystemComponentConfig(): array
    {
        return $this->root->config;
    }

    private function bucket(): string
    {
        return Backends::env('S3_BUCKET');
    }

    private function tree(): array
    {
        return RawTree::of($this->raw);
    }

    private function putRaw(string $key, string $body): void
    {
        $this->client->putObject(['Bucket' => $this->bucket(), 'Key' => $key, 'Body' => $body]);
    }

    /**
     * @return array [ApiResponse, string[] S3 commands]
     */
    private function counted(callable $request): array
    {
        $requests = CountingAwsS3Filesystem::record($request, $response, $exception);
        if ($exception !== null) {
            throw $exception;
        }
        return [$response, $requests];
    }

    private function deliver(string $action, string $path): array
    {
        return $this->counted(function () use ($action, $path) {
            return $action === 'stream' ? $this->apiStream($path) : $this->apiDownload($path);
        });
    }

    private function assertNotFound(ApiResponse $response, string $context): void
    {
        $this->assertStatus(404, $response);
        $this->assertInstanceOf(NotFoundHttpException::class, $response->exception, $context);
        $this->assertNull($response->header('Content-Disposition'), $context);
        $this->assertStringNotContainsString(self::SECRET, $response->body, $context);
        // `download` echoes the requested path (pinned on local); no key of the store may appear
        $this->assertStringNotContainsString($this->prefix . '/', $response->body, $context . ': no storage key in the answer');
        $this->assertStringNotContainsString($this->bucket(), $response->body, $context . ': no bucket in the answer');
    }

    public static function actions(): array
    {
        return ['stream' => ['stream'], 'download' => ['download']];
    }

    // ------------------------------------------------------------------
    // stream and download
    // ------------------------------------------------------------------

    /**
     * @dataProvider actions
     */
    public function testExistingFileIsDeliveredWithOneHeadAndOneGet(string $action): void
    {
        $this->actingAsAdmin();
        $this->raw->write('docs/a.txt', 'hello s3');

        [$response, $requests] = $this->deliver($action, '/docs/a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello s3', $response->body);
        $this->assertSame(['HeadObject', 'GetObject'], $requests);
        $this->assertStringStartsWith('text/plain', (string)$response->header('Content-Type'));
        $this->assertSame('8', $response->header('Content-Length'));
        $this->assertSame(($action === 'stream' ? 'inline' : 'attachment') . '; filename="a.txt"', $response->header('Content-Disposition'));
        $this->assertSame('binary', $response->header('Content-Transfer-Encoding'));
    }

    /**
     * @dataProvider actions
     */
    public function testMissingFileIsNotFoundAfterOneHead(string $action): void
    {
        $this->actingAsAdmin();
        $this->raw->write('docs/a.txt', 'hello');

        [$response, $requests] = $this->deliver($action, '/docs/missing.txt');

        $this->assertNotFound($response, 'missing');
        $this->assertSame(['HeadObject'], $requests);
    }

    /**
     * @dataProvider actions
     */
    public function testDirectoryIsNotFoundAfterOneHead(string $action): void
    {
        $this->actingAsAdmin();
        $this->raw->createDirectory('marker');
        $this->raw->write('implicit/x.txt', 'X');

        foreach (['/marker', '/marker/', '/implicit', '/implicit/'] as $path) {
            [$response, $requests] = $this->deliver($action, $path);
            $this->assertNotFound($response, $path);
            $this->assertSame(['HeadObject'], $requests, $path);
        }
        foreach (['/', ''] as $root) {
            [$response, $requests] = $this->deliver($action, $root);
            $this->assertNotFound($response, 'root');
            $this->assertSame([], $requests, 'the root is never requested');
        }
    }

    /**
     * @dataProvider actions
     */
    public function testPathsLeavingThePrefixAreNotFoundWithoutAnyRequest(string $action): void
    {
        $this->actingAsAdmin();
        $unique = substr($this->prefix, strlen(Backends::S3_BASE_PREFIX) + 1);
        $this->putRaw($this->prefix . '-outside/secret.txt', self::SECRET);
        $this->putRaw($this->prefix . '.txt', self::SECRET);
        $this->raw->write('d/x.txt', 'X');

        $forms = [
            '../' . $unique . '-outside/secret.txt',
            '/../' . $unique . '.txt',
            'd/../../' . $unique . '-outside/secret.txt',
            '..\\' . $unique . '.txt',
            'd\\..\\..\\' . $unique . '.txt',
            '../../' . Backends::S3_BASE_PREFIX . '/' . $unique . '.txt',
            '//..//' . $unique . '.txt',
            '..',
            '../',
            "../{$unique}.txt\0",
            "\t/../{$unique}.txt",
            rawurldecode('%2e%2e/') . $unique . '.txt',
        ];
        foreach ($forms as $path) {
            [$response, $requests] = $this->deliver($action, $path);
            $this->assertNotFound($response, $path);
            $this->assertSame([], $requests, 'no S3 request for ' . json_encode($path));
        }
    }

    /**
     * Names that only look like a way out stay inside the prefix and find nothing there
     *
     * @dataProvider actions
     */
    public function testObjectsOutsideThePrefixAreUnreachableByAnyNameInside(string $action): void
    {
        $this->actingAsAdmin();
        $unique = substr($this->prefix, strlen(Backends::S3_BASE_PREFIX) + 1);
        $this->putRaw($this->prefix . '-outside/secret.txt', self::SECRET);
        $this->putRaw($this->prefix . '.txt', self::SECRET);

        foreach ([
            $unique . '-outside/secret.txt',
            $unique . '.txt',
            '%2e%2e/' . $unique . '.txt',
            '..%2F' . $unique . '.txt',
            '....//' . $unique . '.txt',
            '/' . Backends::S3_BASE_PREFIX . '/' . $unique . '.txt',
        ] as $path) {
            [$response, $requests] = $this->deliver($action, $path);
            $this->assertNotFound($response, $path);
            $this->assertSame(['HeadObject'], $requests, $path);
        }
        $this->assertSame([], $this->tree());
    }

    // ------------------------------------------------------------------
    // list, createFolder, upload, rename, move, remove
    // ------------------------------------------------------------------

    public function testListShowsFilesAndExplicitAndImplicitDirectories(): void
    {
        $this->actingAsAdmin();
        $this->raw->write('a.txt', 'AAA');
        $this->raw->createDirectory('marker');
        $this->raw->write('implicit/x.txt', 'X');

        $response = $this->apiPost('list', ['path' => '/']);

        $this->assertStatus(200, $response);
        $items = [];
        foreach ($response->json()['result'] as $item) {
            $items[$item['path']] = [$item['name'], $item['type'], $item['size']];
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $item['date']);
        }
        ksort($items);
        $this->assertSame([
            'a.txt' => ['a.txt', 'file', 3],
            'implicit' => ['implicit', 'dir', 0],
            'marker' => ['marker', 'dir', 0],
        ], $items);
    }

    public function testCreateFolderWritesAMarkerAndRefusesAnExistingItem(): void
    {
        $this->actingAsAdmin();
        $this->raw->write('f.txt', 'F');

        $this->assertResult(true, '', $this->apiPost('createFolder', ['newPath' => '/new']));
        $this->assertResult(true, '', $this->apiPost('createFolder', ['newPath' => '/a/b']));
        $existing = $this->apiPost('createFolder', ['newPath' => '/new']);
        $onFile = $this->apiPost('createFolder', ['newPath' => '/f.txt']);

        $this->assertFalse($existing->json()['result']['success']);
        $this->assertFalse($onFile->json()['result']['success']);
        $this->assertSame(['a/' => null, 'a/b/' => null, 'f.txt' => 'F', 'new/' => null], $this->tree());
        // the file was seeded outside the API and has no row
        $this->assertSame(['/a/b', '/new'], array_values(array_diff($this->rowPaths(), ['/a'])));
    }

    public function testUploadStoresTheFileAndRefusesAnExistingOne(): void
    {
        $this->actingAsAdmin();
        $this->raw->write('docs/a.txt', 'ORIGINAL');

        $created = $this->apiUpload('/docs', ['b.txt' => 'NEW FILE']);
        $onto = $this->apiUpload('/docs', ['a.txt' => 'REPLACEMENT']);

        $this->assertStatus(200, $created);
        $this->assertSame(['result' => ['success' => true, 'error' => '', 'uploadedFiles' => ['docs/b.txt']]], $created->json());
        $this->assertStatus(400, $onto);
        $this->assertSame(['result' => ['success' => false, 'error' => 'File already exists', 'uploadedFiles' => []]], $onto->json());
        $this->assertSame(['docs/' => null, 'docs/a.txt' => 'ORIGINAL', 'docs/b.txt' => 'NEW FILE'], $this->tree());
    }

    public function testRenameOfAFolderWithChildrenMovesEveryObjectAndItsRows(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/d');
        $this->seedFileViaApi('/d/x.txt', 'X');
        $this->seedDirViaApi('/d/s');
        $this->seedFileViaApi('/d/s/y.txt', 'Y');
        $this->seedDirViaApi('/d/empty');
        $this->seedFileViaApi('/dd.txt', 'sibling');

        $response = $this->apiPost('rename', ['item' => '/d', 'newItemPath' => '/e']);

        $this->assertResult(true, '', $response);
        $this->assertSame(['dd.txt' => 'sibling', 'e/' => null, 'e/empty/' => null, 'e/s/' => null, 'e/s/y.txt' => 'Y', 'e/x.txt' => 'X'], $this->tree());
        $this->assertSame(['/dd.txt', '/e', '/e/empty', '/e/s', '/e/s/y.txt', '/e/x.txt'], $this->rowPaths());
    }

    public function testMoveOfAFolderWithChildrenIntoAnotherFolder(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/src');
        $this->seedFileViaApi('/src/x.txt', 'X');
        $this->seedDirViaApi('/src/s');
        $this->seedFileViaApi('/src/s/y.txt', 'Y');
        $this->seedDirViaApi('/target');

        $response = $this->apiPost('move', ['newPath' => '/target', 'items' => ['/src']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['target/' => null, 'target/src/' => null, 'target/src/s/' => null, 'target/src/s/y.txt' => 'Y', 'target/src/x.txt' => 'X'], $this->tree());
        $this->assertSame(['/target', '/target/src', '/target/src/s', '/target/src/s/y.txt', '/target/src/x.txt'], $this->rowPaths());
    }

    public function testMoveOfAFolderOntoAnExistingFolderIsRefused(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/src');
        $this->seedFileViaApi('/src/x.txt', 'X');
        $this->seedDirViaApi('/target');
        $this->seedDirViaApi('/target/src');
        $this->seedFileViaApi('/target/src/keep.txt', 'KEEP');

        $response = $this->apiPost('move', ['newPath' => '/target', 'items' => ['/src']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['src/' => null, 'src/x.txt' => 'X', 'target/' => null, 'target/src/' => null, 'target/src/keep.txt' => 'KEEP'], $this->tree());
    }

    public function testRemoveOfAFolderDeletesItsObjectsAndKeepsSiblings(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deleteRecursive' => true]);
        $this->seedDirViaApi('/p');
        $this->seedDirViaApi('/p/full');
        $this->seedFileViaApi('/p/full/x.txt', 'X');
        $this->seedDirViaApi('/p/full/sub');
        $this->seedFileViaApi('/p/full/sub/y.txt', 'Y');
        $this->seedFileViaApi('/p/full.txt', 'sibling');
        $this->seedDirViaApi('/p/full-other');

        $response = $this->apiPost('remove', ['items' => ['/p/full']]);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertSame(['p/' => null, 'p/full-other/' => null, 'p/full.txt' => 'sibling'], $this->tree());
        $this->assertSame(['/p', '/p/full-other', '/p/full.txt'], $this->rowPaths());
    }
}
