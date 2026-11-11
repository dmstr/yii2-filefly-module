<?php
// file generated with AI assistance: Claude Code - [2026-11-12 12:00:00 UTC]
/**
 * The storage calls behind ApiController::actionStream() and ApiController::actionDownload():
 * one metadata request (Storage::fileStat()) and one read (Storage::fileStream()) per delivery,
 * instead of separate existence, mime type and size requests, which cost one HEAD request each on
 * S3.
 *
 * Runs against the local filesystem component, so no cloud credentials are needed, and checks
 * that:
 *   1. fileStat() reports what the actions need (type and size) and no mime type
 *   2. fileStream() returns a readable stream resource
 *   3. a missing path, a directory and the root end as "not found" (null or an exception)
 *   4. the mime type by file name (no request) gives sensible values
 */

namespace hrzg\filefly\tests;

use creocoder\flysystem\LocalFilesystem;
use hrzg\filefly\components\Storage;
use PHPUnit\Framework\TestCase;

// BaseObject::__construct() needs the Yii class for configuration
if (!class_exists('Yii', false)) {
    require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
}

class StreamMetadataTest extends TestCase
{
    private string $testDir;

    private Storage $storage;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/filefly-test-' . uniqid();
        mkdir($this->testDir . '/dir', 0755, true);
        file_put_contents($this->testDir . '/hello.txt', 'Hello Filefly Test');

        $this->storage = new Storage(['filesystem' => new LocalFilesystem(['path' => $this->testDir])]);
    }

    protected function tearDown(): void
    {
        @unlink($this->testDir . '/hello.txt');
        @rmdir($this->testDir . '/dir');
        @rmdir($this->testDir);
    }

    /**
     * One fileStat() call delivers type and size, which actionStream()/actionDownload() use
     * instead of separate isFile(), mime type and size requests.
     */
    public function testFileStatReturnsTypeAndSize(): void
    {
        $info = $this->storage->fileStat('hello.txt');

        $this->assertNotNull($info);
        $this->assertSame('file', $info->getType());
        $this->assertSame(18, $info->getFileSize()); // "Hello Filefly Test" = 18 characters
    }

    /**
     * fileStream() returns the stream resource itself, ready for Response::sendStreamAsFile().
     */
    public function testFileStreamReturnsReadableStream(): void
    {
        $stream = $this->storage->fileStream('/hello.txt');

        $this->assertIsResource($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame('Hello Filefly Test', $content);
    }

    /**
     * Not found: the actions map `null` and every exception to NotFoundHttpException, so a
     * missing path, a directory and the root must end in one of the two.
     *
     * @dataProvider notFoundPaths
     */
    public function testMissingFileDirectoryAndRootAreNotFound(string $path): void
    {
        $threwException = false;
        $result = null;

        try {
            $result = $this->storage->fileStat($path);
        } catch (\Throwable $e) {
            $threwException = true;
        }

        $this->assertTrue(
            $threwException || $result === null,
            'fileStat() must return null or throw, so that the controller can answer with NotFoundHttpException'
        );
    }

    public function notFoundPaths(): array
    {
        return [
            'missing file' => ['does-not-exist.txt'],
            'directory' => ['dir'],
            'root' => ['/'],
        ];
    }

    /**
     * fileStream() of a missing file fails with an exception (the actions answer 404).
     */
    public function testFileStreamOfMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->storage->fileStream('does-not-exist.txt');
    }

    /**
     * Mime type by file extension (no request): Storage::mimeTypeByFileName() must give sensible
     * values for common file types and text/plain for unknown ones.
     */
    public function testMimeTypeDetectionByFilename(): void
    {
        $this->assertSame('text/plain', Storage::mimeTypeByFileName('hello.txt'));
        $this->assertSame('image/jpeg', Storage::mimeTypeByFileName('photo.jpg'));
        $this->assertSame('image/jpeg', Storage::mimeTypeByFileName('PHOTO.JPG'));
        $this->assertSame('application/pdf', Storage::mimeTypeByFileName('document.pdf'));
        $this->assertSame('text/plain', Storage::mimeTypeByFileName('no-extension'));
        $this->assertSame('text/plain', Storage::mimeTypeByFileName('unknown.zzzz'));
    }

    /**
     * fileStat() reports no mime type, so the fallback by file name in actionStream()/
     * actionDownload() applies (regression guard: if the storage ever reports one in the same
     * request, this test fails and the fallback can be reconsidered).
     */
    public function testFileStatDoesNotIncludeMimetype(): void
    {
        $info = $this->storage->fileStat('hello.txt');

        $this->assertNull($info->getMimeType());
    }
}
