<?php
// file generated with AI assistance: Claude Code - [2026-11-11 15:30:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;
use yii\web\NotFoundHttpException;

/**
 * Path handling of the `stream` and `download` actions.
 *
 * Both actions read through the storage adapter directly (one metadata call, one read), so the
 * request path must be normalised the way the filesystem facade does it before it reaches the
 * adapter: a path that leaves the storage root must answer like a missing file, and every path that
 * stays inside the root must be delivered exactly as before.
 *
 * Every case runs for both actions, as root user and as a plain user holding only the action's
 * route permission (`filefly_api_stream` / `filefly_api_download`).
 */
class StreamDownloadPathTest extends ApiTestCase
{
    private const OUTSIDE_CONTENT = 'OUTSIDE-THE-STORAGE-ROOT-7f3a';
    private const INSIDE_CONTENT = 'inside content';

    protected function setUp(): void
    {
        parent::setUp();
        // a failing assertion inside a helper that received an ApiResponse would otherwise carry the
        // response (and the rendered exception with its object graph) in its trace arguments; the
        // isolated test process then cannot serialize the failure ("Serialization of 'Closure'")
        ini_set('zend.exception_ignore_args', '1');
    }

    // ------------------------------------------------------------------
    // data providers
    // ------------------------------------------------------------------

    /**
     * Paths that leave the storage root (the "outside" file lives at `<workDir>/outside.txt`,
     * the storage root is `<workDir>/fs`)
     */
    public static function traversalPaths(): array
    {
        return [
            'parent' => ['../outside.txt'],
            'leading slash parent' => ['/../outside.txt'],
            'dir then two parents' => ['d/../../outside.txt'],
            'dot then parent' => ['./../outside.txt'],
            'double slash parent' => ['//..//outside.txt'],
            'parent of root dir' => ['../fs/../outside.txt'],
            'backslash parent' => ['..\\outside.txt'],
            'backslash dir then two parents' => ['d\\..\\..\\outside.txt'],
            'mixed separators' => ['d/..\\../outside.txt'],
            'parent only' => ['..'],
            'parent with trailing slash' => ['../'],
            'NUL byte after traversal' => ["../outside.txt\0"],
            'NUL byte in segment' => ["d/\0/../../outside.txt"],
            'newline after traversal' => ["../outside.txt\n"],
            'tab before traversal' => ["\t/../outside.txt"],
            // decoded once by PHP/Yii in production; the decoded form is `../outside.txt`
            'url-encoded dots, decoded' => [rawurldecode('%2e%2e/outside.txt')],
            'url-encoded slash, decoded' => [rawurldecode('..%2foutside.txt')],
        ];
    }

    /**
     * Paths that look suspicious but stay inside the root and do not exist there
     */
    public static function insideButMissingPaths(): array
    {
        return [
            'four dots' => ['....//outside.txt'],
            'url-encoded dots, literal' => ['%2e%2e/outside.txt'],
            'url-encoded dots and slash, literal' => ['%2e%2e%2foutside.txt'],
            'double url-encoded dots, decoded once' => [rawurldecode('%252e%252e/outside.txt')],
            'absolute path' => ['/etc/hosts'],
            'outside file name inside root' => ['outside.txt'],
            'empty path' => [''],
            'root' => ['/'],
        ];
    }

    /**
     * [request path, disk path, Content-Disposition file name part]
     */
    public static function deliveredPaths(): array
    {
        return [
            'plain' => ['a.txt', 'a.txt', 'filename="a.txt"'],
            'leading slash' => ['/a.txt', 'a.txt', 'filename="a.txt"'],
            'double leading slash' => ['//a.txt', 'a.txt', 'filename="a.txt"'],
            'nested' => ['d/n/a.txt', 'd/n/a.txt', 'filename="a.txt"'],
            'nested leading slash' => ['/d/n/a.txt', 'd/n/a.txt', 'filename="a.txt"'],
            'nested double slashes' => ['d//n//a.txt', 'd/n/a.txt', 'filename="a.txt"'],
            'dot segment' => ['d/./n/a.txt', 'd/n/a.txt', 'filename="a.txt"'],
            'parent staying inside' => ['d/../a.txt', 'a.txt', 'filename="a.txt"'],
            'two parents staying inside' => ['/d/n/../../a.txt', 'a.txt', 'filename="a.txt"'],
            'parent inside nested' => ['d/n/../n/a.txt', 'd/n/a.txt', 'filename="a.txt"'],
            'spaces' => ['/d/with space.txt', 'd/with space.txt', 'filename="with space.txt"; filename*=utf-8\'\'with%20space.txt'],
            'umlauts' => ['/Grüße.txt', 'Grüße.txt', 'filename="Grusse.txt"; filename*=utf-8\'\'Gr%C3%BC%C3%9Fe.txt'],
            'two dots inside name' => ['a..b.txt', 'a..b.txt', 'filename="a..b.txt"'],
            'leading two dots' => ['..hidden', '..hidden', 'filename="..hidden"'],
            'nested leading two dots' => ['d/..hidden', 'd/..hidden', 'filename="..hidden"'],
            'trailing two dots' => ['file..', 'file..', 'filename="file.."'],
            'three dots' => ['d/...', 'd/...', 'filename="..."'],
        ];
    }

    public static function traversalCases(): array
    {
        return self::combine(self::traversalPaths());
    }

    public static function insideButMissingCases(): array
    {
        return self::combine(self::insideButMissingPaths());
    }

    public static function deliveredCases(): array
    {
        return self::combine(self::deliveredPaths());
    }

    public static function actionActorCases(): array
    {
        return self::combine(['' => []]);
    }

    /**
     * Prefixes every data set with [action, actor]
     */
    private static function combine(array $sets): array
    {
        $cases = [];
        foreach (['stream', 'download'] as $action) {
            foreach (['admin', 'user'] as $actor) {
                foreach ($sets as $name => $set) {
                    $cases[trim("$action as $actor: $name", ': ')] = array_merge([$action, $actor], $set);
                }
            }
        }
        return $cases;
    }

    // ------------------------------------------------------------------
    // paths leaving the root
    // ------------------------------------------------------------------

    /**
     * @dataProvider traversalCases
     */
    public function testTraversalIsAnsweredLikeNotFound(string $action, string $actor, string $path): void
    {
        $this->prepare($action, $actor);

        $response = $this->call($action, $path);

        $this->assertAnsweredLikeNotFound($action, $response);
        $this->assertNoLeak($response, $path);
    }

    /**
     * A symlink inside the root pointing to a directory outside: `link/..` is resolved lexically
     * (to the root itself), never through the link by the operating system.
     *
     * @dataProvider actionActorCases
     */
    public function testParentSegmentAfterSymlinkDoesNotLeaveTheRoot(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        mkdir($this->workDir . '/elsewhere/sub', 0755, true);
        symlink($this->workDir . '/elsewhere/sub', $this->fsRoot . '/link');

        // the OS resolves fs/link/../../outside.txt to <workDir>/outside.txt
        $response = $this->call($action, 'link/../../outside.txt');

        $this->assertAnsweredLikeNotFound($action, $response);
        $this->assertNoLeak($response, 'link/../../outside.txt');
    }

    /**
     * @dataProvider insideButMissingCases
     */
    public function testSuspiciousPathInsideRootIsNotFound(string $action, string $actor, string $path): void
    {
        $this->prepare($action, $actor);

        $response = $this->call($action, $path);

        if ($action === 'stream') {
            $this->assertAnsweredLikeNotFound($action, $response);
            $this->assertNoLeak($response, $path);
        } else {
            // download reports a missing file with a Flysystem specific message, pin status and class only
            $reference = $this->call($action, 'missing.txt');
            $this->assertStatus($reference->statusCode, $response);
            $this->assertInstanceOf(get_class($reference->exception), $response->exception);
            $this->assertNull($response->header('Content-Disposition'));
            $this->assertNoLeak($response, $path, false);
        }
        if (is_readable('/etc/hosts') && trim((string)file_get_contents('/etc/hosts')) !== '') {
            $this->assertStringNotContainsString(trim((string)file_get_contents('/etc/hosts')), $response->body);
        }
    }

    /**
     * An absolute path is relative to the storage root
     *
     * @dataProvider actionActorCases
     */
    public function testAbsolutePathResolvesInsideRoot(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        $this->seedFile('/etc/hosts', 'hosts inside the root');

        $response = $this->call($action, '/etc/hosts');

        $this->assertStatus(200, $response);
        $this->assertSame('hosts inside the root', $response->body);
        $this->assertSame($this->disposition($action, 'filename="hosts"'), $response->header('Content-Disposition'));
    }

    /**
     * Control characters (Unicode category C) are rejected by the facade's normalisation,
     * even when such a file exists inside the root (as in 1.7.5)
     *
     * @dataProvider actionActorCases
     */
    public function testControlCharactersAreAnsweredLikeNotFound(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        foreach (["tab\tname.txt", "zero\u{200B}width.txt", "del\x7Fname.txt"] as $name) {
            $this->seedFile($name, self::OUTSIDE_CONTENT);
        }

        foreach (["tab\tname.txt", "zero\u{200B}width.txt", "del\x7Fname.txt", "a.txt\0.png"] as $path) {
            $response = $this->call($action, $path);

            $this->assertAnsweredLikeNotFound($action, $response, json_encode($path));
            $this->assertNoLeak($response, $path);
        }
    }

    // ------------------------------------------------------------------
    // paths inside the root (regressions)
    // ------------------------------------------------------------------

    /**
     * @dataProvider deliveredCases
     */
    public function testPathInsideRootIsDelivered(string $action, string $actor, string $path, string $diskPath, string $filename): void
    {
        $this->prepare($action, $actor);
        $this->seedFile($diskPath, self::INSIDE_CONTENT);

        $response = $this->call($action, $path);

        $this->assertStatus(200, $response);
        $this->assertSame(self::INSIDE_CONTENT, $response->body);
        $this->assertSame('text/plain', $response->header('Content-Type'));
        $this->assertSame((string)strlen(self::INSIDE_CONTENT), (string)$response->header('Content-Length'));
        $this->assertSame($this->disposition($action, $filename), $response->header('Content-Disposition'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertNull($response->header('Content-Security-Policy'));
        if ($action === 'stream') {
            $this->assertSame('public', $response->header('Cache-Control'));
        } else {
            $this->assertSame('File Transfer', $response->header('Content-Description'));
        }
    }

    /**
     * Backslashes are directory separators for the facade (restores the 1.7.5 behaviour)
     *
     * @dataProvider actionActorCases
     */
    public function testBackslashIsDirectorySeparator(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        $this->seedFile('d/n/a.txt', self::INSIDE_CONTENT);

        $response = $this->call($action, 'd\\n\\a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame(self::INSIDE_CONTENT, $response->body);
        $this->assertSame($this->disposition($action, 'filename="a.txt"'), $response->header('Content-Disposition'));
    }

    /**
     * Trailing slash and trailing dot segment address the file itself (restores the 1.7.5 behaviour)
     *
     * @dataProvider actionActorCases
     */
    public function testTrailingSeparatorAddressesTheFile(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        $this->seedFile('d/a.txt', self::INSIDE_CONTENT);

        foreach (['d/a.txt/', 'd/a.txt/.', 'd/a.txt/x/..'] as $path) {
            $response = $this->call($action, $path);

            $this->assertStatus(200, $response);
            $this->assertSame(self::INSIDE_CONTENT, $response->body, $path);
            $this->assertSame($this->disposition($action, 'filename="a.txt"'), $response->header('Content-Disposition'), $path);
        }
    }

    /**
     * The delivery policy judges the file that is actually delivered
     *
     * @dataProvider actionActorCases
     */
    public function testActiveContentBehindDotSegmentsIsSentAsAttachment(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->seedFile('img/x.svg', $svg);

        foreach (['d/../img/x.svg', 'img/./x.svg', 'img/x.svg/.', 'img\\x.svg'] as $path) {
            $response = $this->call($action, $path);

            $this->assertStatus(200, $response);
            $this->assertSame($svg, $response->body, $path);
            $this->assertSame('image/svg+xml', $response->header('Content-Type'), $path);
            $this->assertSame('attachment; filename="x.svg"', $response->header('Content-Disposition'), $path);
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            $this->assertStringContainsString('sandbox', (string)$response->header('Content-Security-Policy'), $path);
        }
    }

    /**
     * @dataProvider actionActorCases
     */
    public function testRangeRequestStillWorks(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        $this->seedFile('d/n/a.txt', '0123456789');

        $response = $this->call($action, '/d/../d/n/a.txt', ['Range' => 'bytes=2-4']);

        $this->assertStatus(206, $response);
        $this->assertSame('234', $response->body);
        $this->assertSame('bytes 2-4/10', $response->header('Content-Range'));
        $this->assertSame('3', (string)$response->header('Content-Length'));
    }

    /**
     * @dataProvider actionActorCases
     */
    public function testMissingFileAndDirectoryAnswerAsBefore(string $action, string $actor): void
    {
        $this->prepare($action, $actor);
        $this->seedDir('/d/n');

        foreach (['nope.txt', '/d/nope.txt', 'd/n', '/d/n/', 'd/n/..'] as $path) {
            $response = $this->call($action, $path);

            $this->assertStatus(404, $response);
            $this->assertInstanceOf(NotFoundHttpException::class, $response->exception, $path);
            if ($action === 'stream') {
                $this->assertSame('', $response->exception->getMessage(), $path);
            }
        }

        if ($action === 'download') {
            $this->assertSame('File not found at path: d/n', $this->call($action, 'd/n')->exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Chooses the acting user and seeds `<workDir>/outside.txt` and the directories `d` and `d/n`
     */
    private function prepare(string $action, string $actor): void
    {
        if ($actor === 'admin') {
            $this->actingAsAdmin();
        } else {
            $this->actingAsUser(2, [], ['filefly_api_' . $action]);
        }
        file_put_contents($this->workDir . '/outside.txt', self::OUTSIDE_CONTENT);
        $this->seedDir('/d/n');
    }

    private function call(string $action, string $path, array $headers = []): ApiResponse
    {
        return $action === 'stream' ? $this->apiStream($path, $headers) : $this->apiDownload($path, $headers);
    }

    private function disposition(string $action, string $filename): string
    {
        return ($action === 'stream' ? 'inline; ' : 'attachment; ') . $filename;
    }

    /**
     * 404 with NotFoundHttpException and empty message, no Content-Disposition: exactly `stream`'s
     * not-found answer. `download` answers a missing file with a message carrying the request or disk
     * path; a rejected path gets the same status and class but must not echo anything.
     */
    private function assertAnsweredLikeNotFound(string $action, ApiResponse $response, string $context = ''): void
    {
        $this->assertStatus(404, $response);
        $this->assertInstanceOf(NotFoundHttpException::class, $response->exception, $context . "\n" . $response->describe());
        $this->assertSame('', $response->exception->getMessage(), $context);
        $this->assertSame('<pre>Not Found: </pre>', $response->body, $context);
        $this->assertNull($response->header('Content-Disposition'), $context);
    }

    /**
     * Neither the outside content nor the request path or a disk path appears in the headers and
     * (with $checkMessage) in body and message; the outside content never appears in the body
     */
    private function assertNoLeak(ApiResponse $response, string $path, bool $checkMessage = true): void
    {
        $context = json_encode($path);
        $message = $response->exception === null ? '' : $response->exception->getMessage();
        $haystacks = $checkMessage ? [$response->body, $message, json_encode($response->headers)] : [json_encode($response->headers)];
        foreach ($haystacks as $haystack) {
            $this->assertStringNotContainsString(self::OUTSIDE_CONTENT, $haystack, $context);
            $this->assertStringNotContainsString($this->workDir, $haystack, $context);
            $this->assertStringNotContainsString('outside', $haystack, $context);
        }
        $this->assertStringNotContainsString(self::OUTSIDE_CONTENT, $response->body, $context);
        $this->assertFileExists($this->workDir . '/outside.txt');
        $this->assertSame(self::OUTSIDE_CONTENT, file_get_contents($this->workDir . '/outside.txt'));
    }
}
