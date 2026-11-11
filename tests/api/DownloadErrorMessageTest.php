<?php
// file generated with AI assistance: Claude Code - [2026-11-11 16:30:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;
use yii\web\NotFoundHttpException;

/**
 * Error messages of the `download` and `stream` actions.
 *
 * The messages of an HttpException are shown to the end user even with debug off. The adapter's own
 * exception messages carry the absolute storage path on disk, so a failure must never be passed on
 * to the client: `download` answers with the translated "File not found at path: {path}" (the
 * requested path, as in 1.7.5), `stream` with an empty message.
 *
 * Every case runs for both actions, as root user and as a plain user holding only the action's
 * route permission (`filefly_api_stream` / `filefly_api_download`).
 */
class DownloadErrorMessageTest extends ApiTestCase
{
    private const FORBIDDEN_WORDS = ['Lstat', 'SplFileInfo', 'fopen', 'failed to open stream'];

    protected function setUp(): void
    {
        parent::setUp();
        // see StreamDownloadPathTest::setUp()
        ini_set('zend.exception_ignore_args', '1');
    }

    /**
     * Requested paths that fail; the setup is done by prepareCase()
     */
    public static function failureCases(): array
    {
        $names = [
            'missing in root' => 'nope.txt',
            'missing in subdirectory' => 'sub/nope.txt',
            'missing below missing directory' => 'nodir/deeper/nope.txt',
            'directory' => 'sub',
            'empty path' => '',
            'hashmap row without file' => 'ghost.txt',
            'symlink as last segment' => 'link.txt',
            'unreadable file' => 'locked.txt',
        ];
        $cases = [];
        foreach (['stream', 'download'] as $action) {
            foreach (['admin', 'user'] as $actor) {
                foreach ($names as $name => $path) {
                    $cases["$action as $actor: $name"] = [$action, $actor, $name, $path];
                }
            }
        }
        return $cases;
    }

    /**
     * @dataProvider failureCases
     */
    public function testFailureIsAnsweredWithoutDiskDetails(string $action, string $actor, string $case, string $path): void
    {
        $this->prepare($action, $actor, $case);

        $response = $this->call($action, $path);

        $this->assertStatus(404, $response);
        $this->assertInstanceOf(NotFoundHttpException::class, $response->exception, $response->describe());
        $this->assertNull($response->header('Content-Disposition'));
        $this->assertNoDiskDetails($response);
    }

    /**
     * @dataProvider failureCases
     */
    public function testShownMessage(string $action, string $actor, string $case, string $path): void
    {
        $this->prepare($action, $actor, $case);

        $response = $this->call($action, $path);

        $this->assertStatus(404, $response);
        $expected = $action === 'download' ? 'File not found at path: ' . $path : '';
        $this->assertSame($expected, $response->exception->getMessage());
        $this->assertSame('<pre>Not Found: ' . $expected . '</pre>', $response->body);
    }

    /**
     * The requested path is user input; the HTML error view must encode it
     *
     * @dataProvider htmlActionActorCases
     */
    public function testRequestedPathIsEncodedInHtmlResponse(string $action, string $actor): void
    {
        $this->prepare($action, $actor, '');
        $path = '<script>alert(1)</script>.txt';

        $response = $this->call($action, $path, ['Accept' => 'text/html']);

        $this->assertStatus(404, $response);
        $this->assertStringNotContainsString('<script>', $response->body);
        $this->assertStringNotContainsString('alert(1)</script>', $response->body);
        if ($action === 'download') {
            $this->assertStringContainsString('&lt;script&gt;', $response->body);
        }
        $this->assertNoDiskDetails($response);
    }

    public static function htmlActionActorCases(): array
    {
        $cases = [];
        foreach (['stream', 'download'] as $action) {
            foreach (['admin', 'user'] as $actor) {
                $cases["$action as $actor"] = [$action, $actor];
            }
        }
        return $cases;
    }

    /**
     * The same failure inside a JSON answer must not leak either
     *
     * @dataProvider htmlActionActorCases
     */
    public function testLeakFreeWithJsonAccept(string $action, string $actor): void
    {
        $this->prepare($action, $actor, 'symlink as last segment');

        $response = $this->call($action, 'link.txt', ['Accept' => 'application/json']);

        $this->assertStatus(404, $response);
        $this->assertNoDiskDetails($response);
    }

    // ------------------------------------------------------------------

    private function prepare(string $action, string $actor, string $case): void
    {
        if ($actor === 'admin') {
            $this->actingAsAdmin();
        } else {
            $this->actingAsUser(2, [], ['filefly_api_' . $action]);
        }
        $this->seedDir('/sub');
        switch ($case) {
            case 'hashmap row without file':
                $this->seedHashmapRow('/ghost.txt', 'file');
                break;
            case 'symlink as last segment':
                file_put_contents($this->workDir . '/target.txt', 'target');
                symlink($this->workDir . '/target.txt', $this->fsRoot . '/link.txt');
                break;
            case 'unreadable file':
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    $this->markTestSkipped('chmod 000 does not restrict root');
                }
                $this->seedFile('/locked.txt', 'secret');
                chmod($this->fsRoot . '/locked.txt', 0000);
                break;
        }
    }

    private function call(string $action, string $path, array $headers = []): ApiResponse
    {
        return $action === 'stream' ? $this->apiStream($path, $headers) : $this->apiDownload($path, $headers);
    }

    /**
     * Neither body, exception message nor headers contain the storage root, the temp dir or the
     * wording of the underlying PHP / Flysystem errors
     */
    private function assertNoDiskDetails(ApiResponse $response): void
    {
        $message = $response->exception === null ? '' : $response->exception->getMessage();
        $tmp = [sys_get_temp_dir(), realpath(sys_get_temp_dir())];
        $forbidden = array_merge([$this->fsRoot, $this->workDir, realpath($this->fsRoot) ?: $this->fsRoot], $tmp);
        $forbidden = array_unique(array_filter(array_map(static function ($p) {
            return rtrim((string)$p, '/');
        }, $forbidden)));

        foreach (['body' => $response->body, 'message' => $message, 'headers' => json_encode($response->headers)] as $where => $haystack) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $haystack, "$where contains $needle");
            }
            foreach (self::FORBIDDEN_WORDS as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $haystack, "$where contains $word");
            }
        }
    }
}
