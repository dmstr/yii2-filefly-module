<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\controllers\ApiController;
use hrzg\filefly\events\FileEvent;
use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;
use yii\base\Event;

/**
 * Characterisation tests of the ApiController actions `remove`, `stream` and `download`.
 *
 * They pin what the HTTP API does today (status, body, headers, files on disk, hashmap rows),
 * also where the behaviour looks unintended; such places are marked with `NOTE`.
 */
class RemoveStreamDownloadTest extends ApiTestCase
{
    /**
     * @var string|null temp directory outside the storage root (symlink targets), removed in tearDown()
     */
    private $outsideDir;

    protected function tearDown(): void
    {
        Event::offAll();
        if ($this->outsideDir !== null && is_dir($this->outsideDir)) {
            foreach (scandir($this->outsideDir) as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($this->outsideDir . '/' . $entry)) {
                    unlink($this->outsideDir . '/' . $entry);
                }
            }
            rmdir($this->outsideDir);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // private helpers
    // ------------------------------------------------------------------

    /**
     * Creates a fresh temp directory outside the storage root with one file `secret.txt`
     */
    private function createOutsideDir(): string
    {
        $this->outsideDir = sys_get_temp_dir() . '/filefly-outside-' . bin2hex(random_bytes(6));
        mkdir($this->outsideDir, 0755);
        file_put_contents($this->outsideDir . '/secret.txt', 'SECRET');
        return $this->outsideDir;
    }

    /**
     * Smallest valid 1x1 PNG
     */
    private function tinyPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /**
     * Response headers as `name => values joined with ', '`, sorted by name
     */
    private function headerMap(ApiResponse $response): array
    {
        $map = [];
        foreach ($response->headers as $name => $values) {
            $map[$name] = implode(', ', $values);
        }
        ksort($map);
        return $map;
    }

    /**
     * Asserts that `Expires` is about `$offset` seconds in the future (no absolute timestamps) and
     * returns the header map with that value replaced by `<future>`
     */
    private function headerMapWithExpiresChecked(ApiResponse $response, int $offset): array
    {
        $map = $this->headerMap($response);
        $this->assertArrayHasKey('expires', $map);
        $expires = strtotime($map['expires']);
        $this->assertEqualsWithDelta(time() + $offset, $expires, 120, 'Expires header');
        $this->assertStringEndsWith(' GMT', $map['expires']);
        $map['expires'] = '<future>';
        return $map;
    }

    private function seedTree(): void
    {
        $this->seedDir('/full');
        $this->seedFile('/full/x.txt', 'x');
        $this->seedFile('/full/sub/y.txt', 'y');
        $this->seedHashmapRow('/full', 'dir');
        $this->seedHashmapRow('/full/x.txt', 'file');
        $this->seedHashmapRow('/full/sub', 'dir');
        $this->seedHashmapRow('/full/sub/y.txt', 'file');
    }

    /**
     * Records [name, filename, errorMessage] of the given controller events into $log
     *
     * @param string[] $names event names
     */
    private function recordEvents(array $names, array &$log): void
    {
        foreach ($names as $name) {
            Event::on(ApiController::class, $name, static function (FileEvent $event) use (&$log, $name) {
                $log[] = [$name, $event->filename, $event->errorMessage];
            });
        }
    }

    private function removeItems(array $items): ApiResponse
    {
        return $this->apiPost('remove', ['items' => $items]);
    }

    // ------------------------------------------------------------------
    // stream: bodies and headers
    // ------------------------------------------------------------------

    public function testStreamOfTextFileSendsFullHeaderSet(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'public',
            'connection' => 'Keep-Alive',
            'content-disposition' => 'inline; filename="a.txt"',
            'content-length' => '5',
            'content-transfer-encoding' => 'binary',
            'content-type' => 'text/plain',
            'expires' => '<future>',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMapWithExpiresChecked($response, 604800));
    }

    public function testStreamExpiresFollowsModuleOptionStreamExpireOffset(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['streamExpireOffset' => 3600]);
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('a.txt');

        $this->assertStatus(200, $response);
        $this->headerMapWithExpiresChecked($response, 3600);
    }

    public function testStreamOfPngSendsImageTypeInlineWithoutCsp(): void
    {
        $this->actingAsAdmin();
        $png = $this->tinyPng();
        $this->seedFile('/pic.png', $png);

        $response = $this->apiStream('pic.png');

        $this->assertStatus(200, $response);
        $this->assertSame($png, $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'public',
            'connection' => 'Keep-Alive',
            'content-disposition' => 'inline; filename="pic.png"',
            'content-length' => (string)strlen($png),
            'content-transfer-encoding' => 'binary',
            'content-type' => 'image/png',
            'expires' => '<future>',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMapWithExpiresChecked($response, 604800));
    }

    public function testStreamOfGifSendsImageType(): void
    {
        $this->actingAsAdmin();
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $this->seedFile('/pic.gif', $gif);

        $response = $this->apiStream('pic.gif');

        $this->assertStatus(200, $response);
        $this->assertSame($gif, $response->body);
        $this->assertSame('image/gif', $response->header('content-type'));
        $this->assertSame('inline; filename="pic.gif"', $response->header('content-disposition'));
    }

    public function testStreamOfSvgIsForcedToAttachmentWithSandboxCsp(): void
    {
        $this->actingAsAdmin();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->seedFile('/logo.svg', $svg);

        $response = $this->apiStream('logo.svg');

        $this->assertStatus(200, $response);
        $this->assertSame($svg, $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'public',
            'connection' => 'Keep-Alive',
            'content-disposition' => 'attachment; filename="logo.svg"',
            'content-length' => (string)strlen($svg),
            'content-security-policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'content-transfer-encoding' => 'binary',
            'content-type' => 'image/svg+xml',
            'expires' => '<future>',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMapWithExpiresChecked($response, 604800));
    }

    public function testStreamOfHtmlFileIsForcedToAttachmentWithSandboxCsp(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/page.html', '<h1>x</h1>');

        $response = $this->apiStream('page.html');

        $this->assertStatus(200, $response);
        $this->assertSame('text/html', $response->header('content-type'));
        $this->assertSame('attachment; filename="page.html"', $response->header('content-disposition'));
        $this->assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $response->header('content-security-policy'));
    }

    public function testStreamOfFileWithUnknownExtensionIsSentAsTextPlain(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/data.zzz', 'abc');

        $response = $this->apiStream('data.zzz');

        // NOTE: current behaviour, looks unintended: an unknown extension gives text/plain, not application/octet-stream
        $this->assertStatus(200, $response);
        $this->assertSame('abc', $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'public',
            'connection' => 'Keep-Alive',
            'content-disposition' => 'inline; filename="data.zzz"',
            'content-length' => '3',
            'content-transfer-encoding' => 'binary',
            'content-type' => 'text/plain',
            'expires' => '<future>',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMapWithExpiresChecked($response, 604800));
    }

    public function testStreamOfFileWithoutExtensionIsSentAsTextPlain(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/README', 'abc');

        $response = $this->apiStream('README');

        // NOTE: current behaviour, looks unintended: no extension gives text/plain, not application/octet-stream
        $this->assertStatus(200, $response);
        $this->assertSame('abc', $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'public',
            'connection' => 'Keep-Alive',
            'content-disposition' => 'inline; filename="README"',
            'content-length' => '3',
            'content-transfer-encoding' => 'binary',
            'content-type' => 'text/plain',
            'expires' => '<future>',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMapWithExpiresChecked($response, 604800));
    }

    public function testStreamOfUpperCaseExtensionUsesLowerCaseMimeLookup(): void
    {
        $this->actingAsAdmin();
        $png = $this->tinyPng();
        $this->seedFile('/PHOTO.PNG', $png);

        $response = $this->apiStream('PHOTO.PNG');

        $this->assertStatus(200, $response);
        $this->assertSame($png, $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'public',
            'connection' => 'Keep-Alive',
            'content-disposition' => 'inline; filename="PHOTO.PNG"',
            'content-length' => (string)strlen($png),
            'content-transfer-encoding' => 'binary',
            'content-type' => 'image/png',
            'expires' => '<future>',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMapWithExpiresChecked($response, 604800));
    }

    public function testStreamOfUpperCaseSvgExtensionIsStillForcedToAttachment(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/LOGO.SVG', '<svg/>');

        $response = $this->apiStream('LOGO.SVG');

        $this->assertStatus(200, $response);
        $this->assertSame('image/svg+xml', $response->header('content-type'));
        $this->assertSame('attachment; filename="LOGO.SVG"', $response->header('content-disposition'));
        $this->assertNotNull($response->header('content-security-policy'));
    }

    public function testStreamOfEmptyFileReturnsEmptyBodyWithZeroLength(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/empty.txt', '');

        $response = $this->apiStream('empty.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('', $response->body);
        $this->assertSame('0', $response->header('content-length'));
        $this->assertSame('text/plain', $response->header('content-type'));
    }

    public function testStreamOfOneMegabyteFileKeepsLengthAndContentHash(): void
    {
        $this->actingAsAdmin();
        $content = '';
        for ($i = 0; $i < 1024; $i++) {
            $content .= random_bytes(1024);
        }
        $this->seedFile('/big.bin', $content);

        $response = $this->apiStream('big.bin');

        $this->assertStatus(200, $response);
        $this->assertSame('1048576', $response->header('content-length'));
        $this->assertSame(1048576, strlen($response->body));
        $this->assertSame(hash('sha256', $content), hash('sha256', $response->body));
        $this->assertSame('application/octet-stream', $response->header('content-type'));
    }

    public function testStreamOfFileInSubdirectoryUsesBasenameInDisposition(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/dir/sub/note.txt', 'deep');

        $response = $this->apiStream('/dir/sub/note.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('deep', $response->body);
        $this->assertSame('inline; filename="note.txt"', $response->header('content-disposition'));
    }

    public function testStreamOfFileNameWithSpaceAndUmlautSendsAsciiAndEncodedFilename(): void
    {
        $this->actingAsAdmin();
        $this->seedFile("/my f\u{e4}ile.txt", 'u');

        $response = $this->apiStream('my f' . "\u{e4}" . 'ile.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('u', $response->body);
        $this->assertSame(
            'inline; filename="my faile.txt"; filename*=utf-8\'\'my%20f%C3%A4ile.txt',
            $response->header('content-disposition')
        );
    }

    public function testStreamDoesNotCreateHashmapRows(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $this->assertStatus(200, $this->apiStream('a.txt'));

        $this->assertSame([], $this->hashmapRows());
    }

    // ------------------------------------------------------------------
    // stream: ranges and verbs
    // ------------------------------------------------------------------

    public function testStreamRangeOfFirstBytesReturns206(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=0-3']);

        $this->assertStatus(206, $response);
        $this->assertSame('0123', $response->body);
        $this->assertSame('bytes 0-3/10', $response->header('content-range'));
        $this->assertSame('4', $response->header('content-length'));
        $this->assertSame('bytes', $response->header('accept-ranges'));
        $this->assertSame('text/plain', $response->header('content-type'));
    }

    public function testStreamRangeInTheMiddleReturns206(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=4-6']);

        $this->assertStatus(206, $response);
        $this->assertSame('456', $response->body);
        $this->assertSame('bytes 4-6/10', $response->header('content-range'));
        $this->assertSame('3', $response->header('content-length'));
    }

    public function testStreamSuffixRangeReturnsLastBytes(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=-4']);

        $this->assertStatus(206, $response);
        $this->assertSame('6789', $response->body);
        $this->assertSame('bytes 6-9/10', $response->header('content-range'));
    }

    public function testStreamOpenEndedRangeReturnsRestOfFile(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=7-']);

        $this->assertStatus(206, $response);
        $this->assertSame('789', $response->body);
        $this->assertSame('bytes 7-9/10', $response->header('content-range'));
    }

    public function testStreamRangeBeyondEndIsClampedToFileSize(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=8-100']);

        $this->assertStatus(206, $response);
        $this->assertSame('89', $response->body);
        $this->assertSame('bytes 8-9/10', $response->header('content-range'));
    }

    public function testStreamRangeStartingBehindEndReturns404WithUnsatisfiedContentRange(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=100-200']);

        // NOTE: current behaviour, looks unintended: the 416 of the response is turned into 404 by the action's catch-all
        $this->assertStatus(404, $response);
        $this->assertSame('bytes */10', $response->header('content-range'));
        $this->assertStringNotContainsString('0123456789', $response->body);
    }

    public function testStreamMalformedRangeReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=abc']);

        // NOTE: current behaviour, looks unintended: an unparsable Range is answered with 404, not with the full file
        $this->assertStatus(404, $response);
        $this->assertStringNotContainsString('0123456789', $response->body);
    }

    public function testStreamInvertedRangeReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiStream('r.txt', ['Range' => 'bytes=5-2']);

        // NOTE: current behaviour, looks unintended: 404 instead of 416
        $this->assertStatus(404, $response);
    }

    public function testStreamRangeOfLargeFileReturnsOnlyRequestedBytes(): void
    {
        $this->actingAsAdmin();
        $content = str_repeat('abcdefghij', 100000);
        $this->seedFile('/big.txt', $content);

        $response = $this->apiStream('big.txt', ['Range' => 'bytes=500000-500009']);

        $this->assertStatus(206, $response);
        $this->assertSame(substr($content, 500000, 10), $response->body);
        $this->assertSame('bytes 500000-500009/1000000', $response->header('content-range'));
    }

    public function testStreamHeadRequestIsRejectedWith405(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $response = $this->request('HEAD', ['action' => 'stream', 'path' => 'a.txt'], null);

        $this->assertStatus(405, $response);
        $this->assertSame('GET', $response->header('allow'));
    }

    public function testStreamPostRequestIsRejectedWith405(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiPost('stream', ['path' => 'a.txt']);

        // the non-GET gate of runAction() lets the admin pass, the VerbFilter rejects the verb
        $this->assertStatus(405, $response);
        $this->assertSame('GET', $response->header('allow'));
    }

    // ------------------------------------------------------------------
    // stream: failures and path forms
    // ------------------------------------------------------------------

    public function testStreamOfMissingPathReturns404WithEmptyMessage(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiStream('nope.txt');

        $this->assertStatus(404, $response);
        $this->assertSame('', $response->exception->getMessage());
        $this->assertNull($response->header('content-disposition'));
        $this->assertSame([], $this->hashmapRows());
    }

    public function testStreamOfDirectoryReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/in.txt', 'in');

        $response = $this->apiStream('d');

        $this->assertStatus(404, $response);
        $this->assertSame('', $response->exception->getMessage());
        $this->assertStringNotContainsString('in', strip_tags($response->body, ''));
    }

    public function testStreamOfEmptyPathReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $this->assertStatus(404, $this->apiStream(''));
    }

    public function testStreamOfRootSlashReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $this->assertStatus(404, $this->apiStream('/'));
    }

    public function testStreamWithoutPathParameterReturns400(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('stream');

        $this->assertStatus(400, $response);
    }

    public function testStreamWithLeadingSlashFindsFile(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('/a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
    }

    public function testStreamWithDoubleSlashesFindsFile(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/a.txt', 'hello');

        $response = $this->apiStream('//d//a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
    }

    public function testStreamWithDotSegmentsInsideRootIsNormalized(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $this->seedDir('/d');

        $response = $this->apiStream('d/../a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
        $this->assertSame('inline; filename="a.txt"', $response->header('content-disposition'));
    }

    public function testStreamWithSingleDotSegmentIsNormalized(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/a.txt', 'hello');

        $response = $this->apiStream('./d/./a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
    }

    public function testStreamWithDotSegmentsLeavingTheRootDeliversFileFromOutsideTheRoot(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        file_put_contents($this->workDir . '/outside.txt', 'OUTSIDE');

        $response = $this->apiStream('../outside.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: path traversal. The action talks to the adapter
        // directly, which does not normalize `..`, so a file next to the storage root is delivered
        $this->assertStatus(200, $response);
        $this->assertSame('OUTSIDE', $response->body);
        $this->assertSame('inline; filename="outside.txt"', $response->header('content-disposition'));
    }

    public function testStreamWithDotSegmentsClimbingOutOfSubdirectoryDeliversFileFromOutsideTheRoot(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/d');
        file_put_contents($this->workDir . '/outside.txt', 'OUTSIDE');

        $response = $this->apiStream('d/../../outside.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: path traversal, see the test above
        $this->assertStatus(200, $response);
        $this->assertSame('OUTSIDE', $response->body);
    }

    public function testStreamDoesNotUrlDecodePercentSequencesInPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $this->seedDir('/d');

        // the harness encodes the query once, so the action receives the literal characters %2e%2e
        $this->assertStatus(404, $this->apiStream('d/%2e%2e/a.txt'));
        $this->assertStatus(404, $this->apiStream('a%2Etxt'));
    }

    public function testStreamFindsFileWhoseNameContainsLiteralPercentSequence(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a%2Etxt', 'percent');

        $response = $this->apiStream('a%2Etxt');

        $this->assertStatus(200, $response);
        $this->assertSame('percent', $response->body);
    }

    public function testStreamOfFileWithoutHashmapRowWorksAndCreatesNoRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/norow.txt', 'content');

        $response = $this->apiStream('norow.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('content', $response->body);
        $this->assertNoHashmapRow('/norow.txt');
    }

    public function testStreamOfHashmapRowWithoutFileOnDiskReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedHashmapRow('/ghost.txt', 'file', [], 5);

        $response = $this->apiStream('ghost.txt');

        $this->assertStatus(404, $response);
        $this->assertHashmapRow('/ghost.txt', ['type' => 'file']);
    }

    public function testStreamIgnoresTheSizeInTheHashmapRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $this->seedHashmapRow('/a.txt', 'file', [], 999);

        $response = $this->apiStream('a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('5', $response->header('content-length'));
    }

    // ------------------------------------------------------------------
    // stream: symlinks
    // ------------------------------------------------------------------

    public function testStreamOfSymlinkToFileInsideRootReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        symlink($this->diskPath('/a.txt'), $this->diskPath('/link-in.txt'));

        $response = $this->apiStream('link-in.txt');

        $this->assertStatus(404, $response);
        $this->assertStringNotContainsString('hello', $response->body);
    }

    public function testStreamOfSymlinkToFileOutsideRootReturns404(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside . '/secret.txt', $this->diskPath('/link-out.txt'));

        $response = $this->apiStream('link-out.txt');

        $this->assertStatus(404, $response);
        $this->assertStringNotContainsString('SECRET', $response->body);
    }

    public function testStreamOfSymlinkToDirectoryReturns404(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside, $this->diskPath('/link-dir'));

        $this->assertStatus(404, $this->apiStream('link-dir'));
    }

    public function testStreamOfFileBehindSymlinkedDirectoryInsideRootIsDelivered(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/real/in.txt', 'inside');
        symlink($this->diskPath('/real'), $this->diskPath('/alias'));

        $response = $this->apiStream('alias/in.txt');

        // NOTE: current behaviour, looks unintended: only the last path segment is checked for being a link
        $this->assertStatus(200, $response);
        $this->assertSame('inside', $response->body);
    }

    public function testStreamOfFileBehindSymlinkedDirectoryOutsideRootIsDelivered(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside, $this->diskPath('/escape'));

        $response = $this->apiStream('escape/secret.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: a symlinked directory inside the storage root
        // lets stream deliver files from outside the root (only the last path segment is checked for links)
        $this->assertStatus(200, $response);
        $this->assertSame('SECRET', $response->body);
        $this->assertSame('inline; filename="secret.txt"', $response->header('content-disposition'));
    }

    // ------------------------------------------------------------------
    // stream: access
    // ------------------------------------------------------------------

    public function testStreamForGuestWithJsonAcceptReturns403LoginRequired(): void
    {
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('a.txt');

        $this->assertStatus(403, $response);
        $this->assertSame('Login Required', $response->exception->getMessage());
        $this->assertStringNotContainsString('hello', $response->body);
    }

    public function testStreamForGuestWithHtmlAcceptRedirectsToLogin(): void
    {
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('a.txt', ['Accept' => 'text/html']);

        $this->assertStatus(302, $response);
        $this->assertSame('http://localhost/index.php?r=user%2Fsecurity%2Flogin', $response->header('location'));
        $this->assertSame('', $response->body);
    }

    public function testStreamForUserWithRoutePermissionWorks(): void
    {
        $this->actingAsUser(7, [], ['filefly_api_stream']);
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
    }

    public function testStreamForUserWithoutRoutePermissionReturns403(): void
    {
        $this->actingAsUser(7);
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiStream('a.txt');

        $this->assertStatus(403, $response);
        $this->assertStringNotContainsString('hello', $response->body);
    }

    public function testStreamIgnoresTheReadPermissionOfTheHashmapRow(): void
    {
        $this->actingAsUser(7, [], ['filefly_api_stream']);
        $this->seedFile('/private.txt', 'secret');
        $this->seedHashmapRow('/private.txt', 'file', [
            'access_owner' => '9',
            'access_read' => 'SomeRoleTheUserLacks',
        ]);

        $response = $this->apiStream('private.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: stream does not check access_read of the item
        $this->assertStatus(200, $response);
        $this->assertSame('secret', $response->body);
    }

    // ------------------------------------------------------------------
    // download
    // ------------------------------------------------------------------

    public function testDownloadOfTextFileSendsFullHeaderSet(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiDownload('a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'must-revalidate, post-check=0, pre-check=0',
            'connection' => 'Keep-Alive',
            'content-description' => 'File Transfer',
            'content-disposition' => 'attachment; filename="a.txt"',
            'content-length' => '5',
            'content-transfer-encoding' => 'binary',
            'content-type' => 'text/plain',
            'expires' => '0',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMap($response));
    }

    public function testDownloadOfPngIsAttachmentWithImageType(): void
    {
        $this->actingAsAdmin();
        $png = $this->tinyPng();
        $this->seedFile('/pic.png', $png);

        $response = $this->apiDownload('pic.png');

        $this->assertStatus(200, $response);
        $this->assertSame($png, $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'must-revalidate, post-check=0, pre-check=0',
            'connection' => 'Keep-Alive',
            'content-description' => 'File Transfer',
            'content-disposition' => 'attachment; filename="pic.png"',
            'content-length' => (string)strlen($png),
            'content-transfer-encoding' => 'binary',
            'content-type' => 'image/png',
            'expires' => '0',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMap($response));
    }

    public function testDownloadOfSvgSendsSandboxCsp(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/logo.svg', '<svg/>');

        $response = $this->apiDownload('logo.svg');

        $this->assertStatus(200, $response);
        $this->assertSame('<svg/>', $response->body);
        $this->assertSame([
            'accept-ranges' => 'bytes',
            'access-control-allow-credentials' => 'false',
            'cache-control' => 'must-revalidate, post-check=0, pre-check=0',
            'connection' => 'Keep-Alive',
            'content-description' => 'File Transfer',
            'content-disposition' => 'attachment; filename="logo.svg"',
            'content-length' => '6',
            'content-security-policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'content-transfer-encoding' => 'binary',
            'content-type' => 'image/svg+xml',
            'expires' => '0',
            'pragma' => 'public',
            'x-content-type-options' => 'nosniff',
        ], $this->headerMap($response));
    }

    public function testDownloadOfUnknownAndMissingExtensionIsSentAsTextPlain(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/data.zzz', 'abc');
        $this->seedFile('/README', 'abc');

        $unknown = $this->apiDownload('data.zzz');
        $none = $this->apiDownload('README');

        // NOTE: current behaviour, looks unintended: text/plain instead of application/octet-stream
        $this->assertStatus(200, $unknown);
        $this->assertSame('text/plain', $unknown->header('content-type'));
        $this->assertSame('attachment; filename="data.zzz"', $unknown->header('content-disposition'));
        $this->assertStatus(200, $none);
        $this->assertSame('text/plain', $none->header('content-type'));
        $this->assertSame('attachment; filename="README"', $none->header('content-disposition'));
    }

    public function testDownloadOfUpperCaseExtensionUsesLowerCaseMimeLookup(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/PHOTO.PNG', $this->tinyPng());

        $response = $this->apiDownload('PHOTO.PNG');

        $this->assertStatus(200, $response);
        $this->assertSame('image/png', $response->header('content-type'));
        $this->assertSame('attachment; filename="PHOTO.PNG"', $response->header('content-disposition'));
    }

    public function testDownloadOfEmptyFileReturnsEmptyBody(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/empty.txt', '');

        $response = $this->apiDownload('empty.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('', $response->body);
        $this->assertSame('0', $response->header('content-length'));
    }

    public function testDownloadOfOneMegabyteFileKeepsLengthAndContentHash(): void
    {
        $this->actingAsAdmin();
        $content = '';
        for ($i = 0; $i < 1024; $i++) {
            $content .= random_bytes(1024);
        }
        $this->seedFile('/big.bin', $content);

        $response = $this->apiDownload('big.bin');

        $this->assertStatus(200, $response);
        $this->assertSame('1048576', $response->header('content-length'));
        $this->assertSame(hash('sha256', $content), hash('sha256', $response->body));
    }

    public function testDownloadHonoursRangeRequestsLikeStream(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/r.txt', '0123456789');

        $response = $this->apiDownload('r.txt', ['Range' => 'bytes=2-5']);

        $this->assertStatus(206, $response);
        $this->assertSame('2345', $response->body);
        $this->assertSame('bytes 2-5/10', $response->header('content-range'));
    }

    public function testDownloadOfMissingPathReturns404(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiDownload('nope.txt');

        // the message of this 404 contains the disk path, so only the status is pinned
        $this->assertStatus(404, $response);
        $this->assertNull($response->header('content-disposition'));
    }

    public function testDownloadOfDirectoryReturns404WithPathInMessage(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/in.txt', 'in');

        $response = $this->apiDownload('d');

        // difference to stream: the 404 carries the message `File not found at path: d`
        $this->assertStatus(404, $response);
        $this->assertSame('File not found at path: d', $response->exception->getMessage());
    }

    public function testDownloadOfEmptyPathReturns404WithEmptyPathInMessage(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiDownload('');

        $this->assertStatus(404, $response);
        $this->assertSame('File not found at path: ', $response->exception->getMessage());
    }

    public function testDownloadWithDotSegmentsLeavingTheRootDeliversFileFromOutsideTheRoot(): void
    {
        $this->actingAsAdmin();
        file_put_contents($this->workDir . '/outside.txt', 'OUTSIDE');

        $response = $this->apiDownload('../outside.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: path traversal, same as stream
        $this->assertStatus(200, $response);
        $this->assertSame('OUTSIDE', $response->body);
        $this->assertSame('attachment; filename="outside.txt"', $response->header('content-disposition'));
    }

    public function testDownloadWithDotSegmentsInsideRootIsNormalized(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $this->seedDir('/d');

        $response = $this->apiDownload('/d/../a.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('hello', $response->body);
    }

    public function testDownloadOfFileWithoutHashmapRowWorksAndCreatesNoRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/norow.txt', 'content');

        $response = $this->apiDownload('norow.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('content', $response->body);
        $this->assertNoHashmapRow('/norow.txt');
    }

    public function testDownloadOfHashmapRowWithoutFileOnDiskReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedHashmapRow('/ghost.txt', 'file', [], 5);

        $this->assertStatus(404, $this->apiDownload('ghost.txt'));
        $this->assertHashmapRow('/ghost.txt');
    }

    public function testDownloadOfSymlinkToFileInsideRootReturns404(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        symlink($this->diskPath('/a.txt'), $this->diskPath('/link-in.txt'));

        $response = $this->apiDownload('link-in.txt');

        $this->assertStatus(404, $response);
        $this->assertStringNotContainsString('hello', $response->body);
    }

    public function testDownloadOfSymlinkToFileOutsideRootReturns404(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside . '/secret.txt', $this->diskPath('/link-out.txt'));

        $response = $this->apiDownload('link-out.txt');

        $this->assertStatus(404, $response);
        $this->assertStringNotContainsString('SECRET', $response->body);
    }

    public function testDownloadOfFileBehindSymlinkedDirectoryOutsideRootIsDelivered(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside, $this->diskPath('/escape'));

        $response = $this->apiDownload('escape/secret.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: same as stream, a symlinked directory leaks files
        $this->assertStatus(200, $response);
        $this->assertSame('SECRET', $response->body);
        $this->assertSame('attachment; filename="secret.txt"', $response->header('content-disposition'));
    }

    public function testDownloadHeadRequestIsRejectedWith405(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $response = $this->request('HEAD', ['action' => 'download', 'path' => 'a.txt'], null);

        $this->assertStatus(405, $response);
    }

    public function testDownloadForGuestWithJsonAcceptReturns403(): void
    {
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiDownload('a.txt');

        $this->assertStatus(403, $response);
        $this->assertStringNotContainsString('hello', $response->body);
    }

    public function testDownloadForGuestWithHtmlAcceptRedirectsToLogin(): void
    {
        $this->seedFile('/a.txt', 'hello');

        $response = $this->apiDownload('a.txt', ['Accept' => 'text/html']);

        $this->assertStatus(302, $response);
        $this->assertSame('http://localhost/index.php?r=user%2Fsecurity%2Flogin', $response->header('location'));
    }

    public function testDownloadIgnoresTheReadPermissionOfTheHashmapRow(): void
    {
        $this->actingAsUser(7, [], ['filefly_api_download']);
        $this->seedFile('/private.txt', 'secret');
        $this->seedHashmapRow('/private.txt', 'file', [
            'access_owner' => '9',
            'access_read' => 'SomeRoleTheUserLacks',
        ]);

        $response = $this->apiDownload('private.txt');

        // NOTE: SECURITY, current behaviour, looks unintended: download does not check access_read of the item
        $this->assertStatus(200, $response);
        $this->assertSame('secret', $response->body);
    }

    // ------------------------------------------------------------------
    // events of stream and download
    // ------------------------------------------------------------------

    public function testDownloadTriggersBeforeAndAfterDownloadOnceEachWithTheRequestedPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/a.txt', 'hello');
        $log = [];
        $this->recordEvents([FileEvent::EVENT_BEFORE_DOWNLOAD, FileEvent::EVENT_AFTER_DOWNLOAD], $log);

        $this->assertStatus(200, $this->apiDownload('/d/a.txt'));

        $this->assertSame([
            [FileEvent::EVENT_BEFORE_DOWNLOAD, '/d/a.txt', null],
            [FileEvent::EVENT_AFTER_DOWNLOAD, '/d/a.txt', null],
        ], $log);
    }

    public function testDownloadOfMissingFileTriggersNoDownloadEvent(): void
    {
        $this->actingAsAdmin();
        $log = [];
        $this->recordEvents([FileEvent::EVENT_BEFORE_DOWNLOAD, FileEvent::EVENT_AFTER_DOWNLOAD], $log);

        $this->assertStatus(404, $this->apiDownload('nope.txt'));

        $this->assertSame([], $log);
    }

    public function testStreamTriggersNoDownloadEvent(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $log = [];
        $this->recordEvents([FileEvent::EVENT_BEFORE_DOWNLOAD, FileEvent::EVENT_AFTER_DOWNLOAD], $log);

        $this->assertStatus(200, $this->apiStream('a.txt'));

        $this->assertSame([], $log);
    }

    // ------------------------------------------------------------------
    // remove
    // ------------------------------------------------------------------

    public function testRemoveOfOneFileDeletesFileAndHashmapRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'hello');
        $this->seedFileViaApi('/keep.txt', 'keep');

        $response = $this->removeItems(['/a.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/a.txt');
        $this->assertNoHashmapRow('/a.txt');
        $this->assertFileOnDisk('/keep.txt', 'keep');
        $this->assertHashmapRow('/keep.txt');
    }

    public function testRemoveOfSeveralItemsDeletesAllOfThem(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');
        $this->seedFileViaApi('/b.txt', 'b');
        $this->seedFileViaApi('/keep.txt', 'keep');

        $response = $this->removeItems(['/a.txt', '/b.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/a.txt');
        $this->assertNotOnDisk('/b.txt');
        $this->assertNoHashmapRow('/a.txt');
        $this->assertNoHashmapRow('/b.txt');
        $this->assertFileOnDisk('/keep.txt');
        $this->assertHashmapRow('/keep.txt');
    }

    public function testRemoveOfPathWithoutLeadingSlashDeletesFileButReportsFailureAndKeepsRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');

        $response = $this->removeItems(['a.txt']);

        // NOTE: current behaviour, looks unintended: the file is deleted, but the row is looked up under the literal
        // path `a.txt` and not found, so the response reports a failure and the row of `/a.txt` is left orphaned
        $this->assertStatus(200, $response);
        $this->assertSame(
            ['result' => ['success' => false, 'error' => 'You are not allowed to delete some records']],
            $response->json()
        );
        $this->assertNotOnDisk('/a.txt');
        $this->assertHashmapRow('/a.txt');
    }

    public function testRemoveOfFileInSubdirectoryKeepsTheDirectory(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/d');
        $this->seedFileViaApi('/d/a.txt', 'a');

        $response = $this->removeItems(['/d/a.txt']);

        $this->assertStatus(200, $response);
        $this->assertTrue($response->json()['result']['success']);
        $this->assertNotOnDisk('/d/a.txt');
        $this->assertDirOnDisk('/d');
        $this->assertHashmapRow('/d', ['type' => 'dir']);
        $this->assertNoHashmapRow('/d/a.txt');
    }

    public function testRemoveOfEmptyDirectoryDeletesDirectoryAndHashmapRow(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/empty');

        $response = $this->removeItems(['/empty']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/empty');
        $this->assertNoHashmapRow('/empty');
    }

    public function testRemoveOfNonEmptyDirectoryWithDeleteRecursiveOffStillDeletesEverything(): void
    {
        $this->actingAsAdmin();
        $this->seedTree();
        $this->seedFile('/keep.txt', 'keep');
        $this->seedHashmapRow('/keep.txt', 'file');

        $response = $this->removeItems(['/full']);

        // NOTE: current behaviour, looks unintended: with deleteRecursive off the response reports success AND an
        // error message, but the directory, all children and all their hashmap rows are deleted anyway
        $this->assertStatus(200, $response);
        $this->assertSame(
            ['result' => ['success' => true, 'error' => 'You are not allowed to delete some records']],
            $response->json()
        );
        $this->assertNotOnDisk('/full');
        $this->assertNotOnDisk('/full/x.txt');
        $this->assertNotOnDisk('/full/sub/y.txt');
        $this->assertSame([], $this->hashmapRows('/full'));
        $this->assertFileOnDisk('/keep.txt', 'keep');
        $this->assertHashmapRow('/keep.txt');
    }

    public function testRemoveOfNonEmptyDirectoryWithDeleteRecursiveOnDeletesEverythingWithoutError(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deleteRecursive' => true]);
        $this->seedTree();
        $this->seedFile('/keep.txt', 'keep');
        $this->seedHashmapRow('/keep.txt', 'file');
        $this->seedHashmapRow('/full2', 'dir');

        $response = $this->removeItems(['/full']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/full');
        $this->assertSame([], $this->hashmapRows('/full/'));
        $this->assertNoHashmapRow('/full');
        $this->assertFileOnDisk('/keep.txt');
        $this->assertHashmapRow('/keep.txt');
        $this->assertHashmapRow('/full2');
    }

    public function testRemoveOfDirectoryWhoseChildrenHaveNoHashmapRowsWithDeleteRecursiveOffReportsNoError(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/full');
        $this->seedFile('/full/x.txt', 'x');
        $this->seedHashmapRow('/full', 'dir');

        $response = $this->removeItems(['/full']);

        // the emptiness check only looks at hashmap rows, so the unmanaged child does not count
        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/full');
        $this->assertNoHashmapRow('/full');
    }

    public function testRemoveOfDirectoryDoesNotTouchSiblingWithSamePrefix(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deleteRecursive' => true]);
        $this->seedDir('/full');
        $this->seedDir('/full-other');
        $this->seedFile('/full-other/z.txt', 'z');
        $this->seedHashmapRow('/full', 'dir');
        $this->seedHashmapRow('/full-other', 'dir');
        $this->seedHashmapRow('/full-other/z.txt', 'file');

        $response = $this->removeItems(['/full']);

        $this->assertStatus(200, $response);
        $this->assertTrue($response->json()['result']['success']);
        $this->assertFileOnDisk('/full-other/z.txt', 'z');
        $this->assertHashmapRow('/full-other');
        $this->assertHashmapRow('/full-other/z.txt');
        $this->assertNoHashmapRow('/full');
    }

    public function testRemoveOfMissingItemReturns500AndLeavesAHashmapRowBehind(): void
    {
        $this->actingAsAdmin();

        $response = $this->removeItems(['/nonexist.txt']);

        $this->assertStatus(500, $response);
        // NOTE: current behaviour, looks unintended: the failed removal is not a JSON error, and the self-heal step
        // has already created a hashmap row without type for the path that does not exist
        $this->assertHashmapRow('/nonexist.txt', ['type' => null]);
        $this->assertNotOnDisk('/nonexist.txt');
    }

    public function testRemoveOfSameFileTwiceFailsTheSecondTimeWith500(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');

        $this->assertStatus(200, $this->removeItems(['/a.txt']));
        $second = $this->removeItems(['/a.txt']);

        $this->assertStatus(500, $second);
        $this->assertNotOnDisk('/a.txt');
    }

    public function testRemoveOfMixedListStopsAtTheFailingItemAndKeepsTheRest(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/b.txt', 'b');
        $this->seedFileViaApi('/c.txt', 'c');

        $response = $this->removeItems(['/b.txt', '/nonexist.txt', '/c.txt']);

        // NOTE: current behaviour, looks unintended: the items before the failure are gone, the items after it are
        // untouched, and the client gets a 500 instead of a result that says what was deleted
        $this->assertStatus(500, $response);
        $this->assertNotOnDisk('/b.txt');
        $this->assertNoHashmapRow('/b.txt');
        $this->assertFileOnDisk('/c.txt', 'c');
        $this->assertHashmapRow('/c.txt');
    }

    public function testRemoveOfFileWithoutHashmapRowDeletesFileAndLeavesNoRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/norow.txt', 'n');

        $response = $this->removeItems(['/norow.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/norow.txt');
        $this->assertNoHashmapRow('/norow.txt');
    }

    public function testRemoveOfHashmapRowWithoutFileOnDiskReturns500AndKeepsTheRow(): void
    {
        $this->actingAsAdmin();
        $this->seedHashmapRow('/ghost.txt', 'file');

        $response = $this->removeItems(['/ghost.txt']);

        // NOTE: current behaviour, looks unintended: an orphaned row can not be removed through the API
        $this->assertStatus(500, $response);
        $this->assertHashmapRow('/ghost.txt', ['type' => 'file']);
    }

    public function testRemoveOfHashmapDirRowWithoutDirectoryOnDiskReturns500AndKeepsTheRow(): void
    {
        $this->actingAsAdmin();
        $this->seedHashmapRow('/ghostdir', 'dir');

        $response = $this->removeItems(['/ghostdir']);

        $this->assertStatus(500, $response);
        $this->assertHashmapRow('/ghostdir', ['type' => 'dir']);
    }

    public function testRemoveOfSymlinkToFileInsideRootFailsAndKeepsLinkAndTarget(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        symlink($this->diskPath('/a.txt'), $this->diskPath('/link-in.txt'));

        $response = $this->removeItems(['/link-in.txt']);

        $this->assertStatus(500, $response);
        $this->assertTrue(is_link($this->diskPath('/link-in.txt')));
        $this->assertFileOnDisk('/a.txt', 'hello');
    }

    public function testRemoveOfSymlinkToFileOutsideRootFailsAndKeepsTarget(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside . '/secret.txt', $this->diskPath('/link-out.txt'));

        $response = $this->removeItems(['/link-out.txt']);

        $this->assertStatus(500, $response);
        $this->assertTrue(is_link($this->diskPath('/link-out.txt')));
        $this->assertSame('SECRET', file_get_contents($outside . '/secret.txt'));
        $this->assertNoHashmapRow('/link-out.txt');
    }

    public function testRemoveOfSymlinkToDirectoryOutsideRootFailsAndKeepsTargetContent(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside, $this->diskPath('/link-dir'));

        $response = $this->removeItems(['/link-dir']);

        $this->assertStatus(500, $response);
        $this->assertTrue(is_link($this->diskPath('/link-dir')));
        $this->assertSame('SECRET', file_get_contents($outside . '/secret.txt'));
    }

    public function testRemoveOfFileBehindSymlinkedDirectoryOutsideRootDeletesTheOutsideFile(): void
    {
        $this->actingAsAdmin();
        $outside = $this->createOutsideDir();
        symlink($outside, $this->diskPath('/escape'));

        $response = $this->removeItems(['/escape/secret.txt']);

        // NOTE: SECURITY, current behaviour, looks unintended: remove deletes a file outside the storage root
        // when the path goes through a symlinked directory
        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertFalse(file_exists($outside . '/secret.txt'));
        $this->assertNoHashmapRow('/escape/secret.txt');
    }

    public function testRemoveOfRootReturns500AndDeletesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');
        $this->seedDirViaApi('/d');

        $response = $this->removeItems(['/']);

        $this->assertStatus(500, $response);
        $this->assertFileOnDisk('/a.txt', 'a');
        $this->assertDirOnDisk('/d');
        $this->assertHashmapRow('/a.txt');
        $this->assertHashmapRow('/d');
    }

    public function testRemoveWithDotSegmentsLeavingTheRootReturns500AndKeepsOutsideFile(): void
    {
        $this->actingAsAdmin();
        file_put_contents($this->workDir . '/outside.txt', 'OUTSIDE');

        $response = $this->removeItems(['/../outside.txt']);

        $this->assertStatus(500, $response);
        $this->assertSame('OUTSIDE', file_get_contents($this->workDir . '/outside.txt'));
    }

    public function testRemoveWithDotSegmentsInsideRootDeletesFileButLeavesRowUnderTheLiteralPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/d/x.txt', 'x');

        $response = $this->removeItems(['/d/../d/x.txt']);

        // NOTE: current behaviour, looks unintended: the file is deleted, but the hashmap row of the normalized
        // path /d/x.txt stays, and removal of the literal path /d/../d/x.txt is what the response reports
        $this->assertStatus(200, $response);
        $this->assertNotOnDisk('/d/x.txt');
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertHashmapRow('/d/x.txt');
        $this->assertNoHashmapRow('/d/../d/x.txt');
    }

    public function testRemoveWithEmptyItemsListReturnsSuccessFalseWithoutError(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');

        $response = $this->removeItems([]);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => false, 'error' => '']], $response->json());
        $this->assertFileOnDisk('/a.txt');
        $this->assertHashmapRow('/a.txt');
    }

    public function testRemoveWithoutItemsReturns400(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('remove');

        $this->assertStatus(400, $response);
    }

    public function testRemoveViaGetIsRejectedWith405AndDeletesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');

        $response = $this->apiGet('remove', ['items' => json_encode(['/a.txt'])]);

        $this->assertStatus(405, $response);
        $this->assertFileOnDisk('/a.txt');
    }

    public function testRemoveByUserWithoutDeletePermissionIsDeniedAndKeepsFileAndRow(): void
    {
        $this->actingAsUser(7, ['FileflyDefault'], ['filefly_api_remove']);
        $this->seedFile('/b.txt', 'b');
        $this->seedHashmapRow('/b.txt', 'file', ['access_owner' => '9', 'access_delete' => 'SomeRoleTheUserLacks']);

        $response = $this->removeItems(['/b.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(
            ['result' => ['success' => false, 'error' => 'You are not allowed to delete some records']],
            $response->json()
        );
        $this->assertFileOnDisk('/b.txt', 'b');
        $this->assertHashmapRow('/b.txt', ['type' => 'file']);
    }

    public function testRemoveByOwnerWithoutExplicitDeleteRoleDeletesFileAndRow(): void
    {
        $this->actingAsUser(7, ['FileflyDefault'], ['filefly_api_remove']);
        $this->seedFile('/b.txt', 'b');
        $this->seedHashmapRow('/b.txt', 'file', ['access_owner' => '7']);

        $response = $this->removeItems(['/b.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => true, 'error' => '']], $response->json());
        $this->assertNotOnDisk('/b.txt');
        $this->assertNoHashmapRow('/b.txt');
    }

    public function testRemoveForGuestReturns403AndDeletesNothing(): void
    {
        $this->seedFile('/a.txt', 'a');

        $response = $this->removeItems(['/a.txt']);

        $this->assertStatus(403, $response);
        $this->assertFileOnDisk('/a.txt', 'a');
    }

    // ------------------------------------------------------------------
    // remove: events
    // ------------------------------------------------------------------

    public function testRemoveTriggersBeforeAfterAndSuccessEventsPerItem(): void
    {
        $this->actingAsAdmin();
        $this->seedFileViaApi('/a.txt', 'a');
        $this->seedFileViaApi('/b.txt', 'b');
        $log = [];
        $this->recordEvents([
            FileEvent::EVENT_BEFORE_REMOVE,
            FileEvent::EVENT_AFTER_REMOVE,
            FileEvent::EVENT_REMOVE_SUCCESS,
            FileEvent::EVENT_REMOVE_ERROR,
        ], $log);

        $this->assertStatus(200, $this->removeItems(['/a.txt', '/b.txt']));

        $this->assertSame([
            [FileEvent::EVENT_BEFORE_REMOVE, '/a.txt', null],
            [FileEvent::EVENT_AFTER_REMOVE, '/a.txt', null],
            [FileEvent::EVENT_BEFORE_REMOVE, '/b.txt', null],
            [FileEvent::EVENT_AFTER_REMOVE, '/b.txt', null],
            [FileEvent::EVENT_REMOVE_SUCCESS, ['/a.txt', '/b.txt'], null],
        ], $log);
    }

    public function testRemoveByUserWithoutDeletePermissionTriggersBeforeRemoveAndRemoveError(): void
    {
        $this->actingAsUser(7, ['FileflyDefault'], ['filefly_api_remove']);
        $this->seedFile('/b.txt', 'b');
        $this->seedHashmapRow('/b.txt', 'file', ['access_owner' => '9', 'access_delete' => 'SomeRoleTheUserLacks']);
        $log = [];
        $this->recordEvents([
            FileEvent::EVENT_BEFORE_REMOVE,
            FileEvent::EVENT_AFTER_REMOVE,
            FileEvent::EVENT_REMOVE_SUCCESS,
            FileEvent::EVENT_REMOVE_ERROR,
        ], $log);

        $this->assertStatus(200, $this->removeItems(['/b.txt']));

        $this->assertSame([
            [FileEvent::EVENT_BEFORE_REMOVE, '/b.txt', null],
            [FileEvent::EVENT_REMOVE_ERROR, null, 'permission_delete_denied'],
        ], $log);
    }

    public function testRemoveOfMissingItemTriggersOnlyBeforeRemove(): void
    {
        $this->actingAsAdmin();
        $log = [];
        $this->recordEvents([
            FileEvent::EVENT_BEFORE_REMOVE,
            FileEvent::EVENT_AFTER_REMOVE,
            FileEvent::EVENT_REMOVE_SUCCESS,
            FileEvent::EVENT_REMOVE_ERROR,
        ], $log);

        $this->assertStatus(500, $this->removeItems(['/nonexist.txt']));

        $this->assertSame([[FileEvent::EVENT_BEFORE_REMOVE, '/nonexist.txt', null]], $log);
    }
}
