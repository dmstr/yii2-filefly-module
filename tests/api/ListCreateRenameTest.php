<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;

/**
 * Characterisation tests for the ApiController actions `list`, `createFolder`, `rename` and `search`.
 *
 * They pin the behaviour of the HTTP API as it is today, including behaviour that looks unintended
 * (marked with `NOTE: current behaviour, looks unintended`). Only HTTP results, files on disk and
 * hashmap rows are asserted, never library internals.
 */
class ListCreateRenameTest extends ApiTestCase
{
    private const DATE_FORMAT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    // ------------------------------------------------------------------
    // private helpers
    // ------------------------------------------------------------------

    /**
     * Expected hashmap row (without id, component and timestamps); defaults are the module's defaultPermissions
     */
    private function row(?string $type, ?string $size = null, string $owner = '1', ?string $read = '*', ?string $update = '*', ?string $delete = '*'): array
    {
        return [
            'type' => $type,
            'size' => $size,
            'access_owner' => $owner,
            'access_read' => $read,
            'access_update' => $update,
            'access_delete' => $delete,
        ];
    }

    /**
     * All hashmap rows as path => row (stable columns only)
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->hashmapRows() as $row) {
            $rows[$row['path']] = [
                'type' => $row['type'],
                'size' => $row['size'],
                'access_owner' => $row['access_owner'],
                'access_read' => $row['access_read'],
                'access_update' => $row['access_update'],
                'access_delete' => $row['access_delete'],
            ];
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }

    private function assertRows(array $expected, string $message = ''): void
    {
        ksort($expected, SORT_STRING);
        $this->assertSame($expected, $this->rows(), $message ?: 'hashmap rows');
    }

    private function assertDisk(array $expected): void
    {
        ksort($expected, SORT_STRING);
        $this->assertSame($expected, $this->disk(), 'files on disk');
    }

    /**
     * Returns the `result` list of a `list` response, sorted by name (byte order; the order of the
     * response itself depends on the directory iteration order of the filesystem)
     */
    private function listResult(ApiResponse $response): array
    {
        $this->assertStatus(200, $response);
        $json = $response->json();
        $this->assertSame(['result'], array_keys($json));
        $items = $json['result'];
        usort($items, static function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return $items;
    }

    /**
     * Asserts one item of a `list` response with the exact key set and order and validates the date format
     */
    private function assertListItem(array $expected, array $actual): void
    {
        $this->assertSame(
            ['name', 'dirname', 'path', 'urls', 'thumbnail', 'preview', 'size', 'date', 'type', 'extension'],
            array_keys($actual)
        );
        $this->assertIsString($actual['date']);
        $this->assertMatchesRegularExpression(self::DATE_FORMAT, $actual['date']);
        unset($actual['date']);
        $this->assertSame($expected, $actual);
    }

    /**
     * Expected list item without the date
     */
    private function item(string $name, string $dirname, string $type, int $size, string $extension): array
    {
        return [
            'name' => $name,
            'dirname' => $dirname,
            'path' => ltrim($dirname . '/' . $name, '/'),
            'urls' => [],
            'thumbnail' => '',
            'preview' => '',
            'size' => $size,
            'type' => $type,
            'extension' => $extension,
        ];
    }

    /**
     * Search result as sorted list of paths (the response order depends on identical timestamps)
     */
    private function searchPaths(ApiResponse $response): array
    {
        $this->assertStatus(200, $response);
        $paths = array_column($response->json(), 'path');
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @var array[] callback name => items received, in call order */
    private static $callbackItems = [];

    /**
     * Sets the three module callbacks to record the `$item` array they receive (static property, because the
     * callbacks run inside the module of the request); they return fixed values.
     */
    private function recordCallbackItems(): void
    {
        self::$callbackItems = ['url' => [], 'thumbnail' => [], 'preview' => []];
        $this->configureModule([
            'urlCallback' => static function ($item) {
                self::$callbackItems['url'][] = $item;
                return ['u'];
            },
            'thumbnailCallback' => static function ($item) {
                self::$callbackItems['thumbnail'][] = $item;
                return 't';
            },
            'previewCallback' => static function ($item) {
                self::$callbackItems['preview'][] = $item;
                return 'p';
            },
        ]);
    }

    /**
     * Items received by one callback, keyed by their `path`
     */
    private function receivedBy(string $callback): array
    {
        $byPath = [];
        foreach (self::$callbackItems[$callback] as $item) {
            $this->assertArrayNotHasKey($item['path'], $byPath, "$callback called twice for " . $item['path']);
            $byPath[$item['path']] = $item;
        }
        ksort($byPath, SORT_STRING);
        return $byPath;
    }

    /**
     * The array the list callbacks receive for a path: exact key set and order; `timestamp` has to be an integer
     * equal to the mtime of the item on disk, `size` is left out for directories, `extension` if the name has no dot.
     */
    private function expectedCallbackItem(string $path, string $type, ?int $size, ?string $extension): array
    {
        clearstatcache();
        $item = [
            'type' => $type,
            'path' => $path,
            'timestamp' => filemtime($this->diskPath($path)),
            'size' => $size,
            'dirname' => dirname($path) === '.' ? '' : dirname($path),
            'basename' => basename($path),
            'extension' => $extension,
            'filename' => pathinfo($path, PATHINFO_FILENAME),
        ];
        return array_filter($item, static function ($value) {
            return $value !== null;
        });
    }

    private function assertCallbackItems(array $expected, array $actual): void
    {
        foreach ($actual as $item) {
            $this->assertIsInt($item['timestamp']);
        }
        $this->assertSame($expected, $actual);
    }

    private function seedSearchFixtures(): void
    {
        $this->seedFile('/docs/report.txt', '1');
        $this->seedFile('/docs/Report-2.PDF', '1');
        $this->seedFile('/img/pic_1.png', '1');
        $this->seedFile('/img/100%.png', '1');
        foreach (['/docs/report.txt', '/docs/Report-2.PDF', '/img/pic_1.png', '/img/100%.png', '/ghost-report.txt'] as $path) {
            $this->seedHashmapRow($path, 'file');
        }
        foreach (['/docs', '/reports'] as $path) {
            $this->seedHashmapRow($path, 'dir');
        }
    }

    // ------------------------------------------------------------------
    // list
    // ------------------------------------------------------------------

    public function testListEmptyRootReturnsEmptyResultList(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('list', ['path' => '/']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => []], $response->json());
        $this->assertRows([]);
    }

    public function testListEmptyDirectoryReturnsEmptyResultList(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/empty');

        $response = $this->apiPost('list', ['path' => '/empty']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => []], $response->json());
        // the listed directory itself gets no row, only its children are repaired
        $this->assertRows([]);
    }

    public function testListFileItemHasExactKeysAndValues(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        $this->assertListItem($this->item('a.txt', '', 'file', 5, 'txt'), $items[0]);
        $this->assertSame(5, $items[0]['size']);
        $this->assertSame([], $items[0]['urls']);
        $this->assertSame('', $items[0]['thumbnail']);
        $this->assertSame('', $items[0]['preview']);
    }

    public function testListDirectoryItemHasSizeZeroAndEmptyExtension(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/sub');
        $this->seedFile('/sub/inner.txt', 'content');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        $this->assertListItem($this->item('sub', '', 'dir', 0, ''), $items[0]);
    }

    public function testListRepairsMissingHashmapRowsWithDefaultPermissions(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $this->seedDir('/sub');

        $this->apiPost('list', ['path' => '/']);

        $this->assertRows([
            '/a.txt' => $this->row('file', '5'),
            '/sub' => $this->row('dir', null),
        ]);
    }

    public function testListKeepsExistingHashmapRowUntouched(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'hello');
        $this->seedHashmapRow('/a.txt', 'file', ['access_owner' => '7', 'access_read' => 'Staff'], 99);

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        // the size in the listing comes from the disk, the row keeps its stale size
        $this->assertSame(5, $items[0]['size']);
        $this->assertRows(['/a.txt' => $this->row('file', '99', '7', 'Staff')]);
    }

    public function testListWithoutRepairCreatesNoRows(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['repair' => false]);
        $this->seedFile('/a.txt', 'hello');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        $this->assertSame('a.txt', $items[0]['name']);
        $this->assertRows([]);
    }

    public function testListReturnsAllItemTypesInDirectory(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedFile('/noext', 'N');
        $this->seedFile('/.hidden', 'H');
        $this->seedFile('/arch.tar.gz', 'G');
        $this->seedDir('/Sub Dir');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        // sorted by name in byte order: ".hidden" < "Sub Dir" < "a.txt" < "arch.tar.gz" < "noext"
        $this->assertCount(5, $items);
        $this->assertListItem($this->item('.hidden', '', 'file', 1, 'hidden'), $items[0]);
        $this->assertListItem($this->item('Sub Dir', '', 'dir', 0, ''), $items[1]);
        $this->assertListItem($this->item('a.txt', '', 'file', 1, 'txt'), $items[2]);
        $this->assertListItem($this->item('arch.tar.gz', '', 'file', 1, 'gz'), $items[3]);
        $this->assertListItem($this->item('noext', '', 'file', 1, ''), $items[4]);
    }

    public function testListNameWithSpacesUmlautsAndUpperCaseKeepsNameAndExtensionCase(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/Sub Dir/Ümläut File.TXT', 'xyz');

        $items = $this->listResult($this->apiPost('list', ['path' => '/Sub Dir']));

        $this->assertCount(1, $items);
        $this->assertListItem($this->item('Ümläut File.TXT', 'Sub Dir', 'file', 3, 'TXT'), $items[0]);
        $this->assertRows(['/Sub Dir/Ümläut File.TXT' => $this->row('file', '3')]);
    }

    public function testListNestedPathReturnsPathsWithoutLeadingSlash(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/b/c.txt', '1');

        $items = $this->listResult($this->apiPost('list', ['path' => '/a/b']));

        $this->assertCount(1, $items);
        $this->assertListItem($this->item('c.txt', 'a/b', 'file', 1, 'txt'), $items[0]);
        $this->assertSame('a/b/c.txt', $items[0]['path']);
        $this->assertRows(['/a/b/c.txt' => $this->row('file', '1')]);
    }

    public function testListAcceptsPathWithoutLeadingSlashAndWithTrailingSlash(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/b.txt', '1');

        $plain = $this->listResult($this->apiPost('list', ['path' => 'a']));
        $trailing = $this->listResult($this->apiPost('list', ['path' => '/a/']));

        $this->assertCount(1, $plain);
        $this->assertSame('a/b.txt', $plain[0]['path']);
        $this->assertCount(1, $trailing);
        $this->assertSame('a/b.txt', $trailing[0]['path']);
    }

    public function testListEmptyPathListsRoot(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/b.txt', '1');

        $items = $this->listResult($this->apiPost('list', ['path' => '']));

        $this->assertCount(1, $items);
        $this->assertSame('a', $items[0]['name']);
        $this->assertSame('dir', $items[0]['type']);
    }

    public function testListMissingPathParameterReturns400(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('list', []);

        $this->assertStatus(400, $response);
    }

    public function testListViaGetReturns405(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('list', ['path' => '/']);

        $this->assertStatus(405, $response);
    }

    public function testListMissingDirectoryReturnsEmptyResultWithoutError(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('list', ['path' => '/nope']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => []], $response->json());
        $this->assertRows([]);
        $this->assertDisk([]);
    }

    public function testListPathThatIsAFileReturnsEmptyResultWithoutError(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'x');

        $response = $this->apiPost('list', ['path' => '/a.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => []], $response->json());
        $this->assertRows([]);
        $this->assertDisk(['/a.txt' => 'x']);
    }

    public function testListIgnoresHashmapRowWithoutFileOnDiskAndKeepsRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/real.txt', 'r');
        $this->seedHashmapRow('/ghost.txt', 'file');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        $this->assertSame('real.txt', $items[0]['name']);
        // NOTE: current behaviour, looks unintended: orphan rows are neither listed nor cleaned up by list
        $this->assertRows([
            '/ghost.txt' => $this->row('file'),
            '/real.txt' => $this->row('file', '1'),
        ]);
    }

    public function testListUsesModuleCallbacksForUrlsThumbnailAndPreview(): void
    {
        $this->actingAsAdmin();
        $this->configureModule([
            'urlCallback' => static function ($item) {
                return ['u:' . $item['path']];
            },
            'thumbnailCallback' => static function ($item) {
                return 't:' . $item['path'];
            },
            'previewCallback' => static function ($item) {
                return 'p:' . $item['path'];
            },
        ]);
        $this->seedFile('/a.txt', '1');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        $this->assertSame(['u:a.txt'], $items[0]['urls']);
        $this->assertSame('t:a.txt', $items[0]['thumbnail']);
        $this->assertSame('p:a.txt', $items[0]['preview']);
    }

    public function testListItemOfDirectoryWithDotInNameHasTheTextAfterTheLastDotAsExtension(): void
    {
        // NOTE: current behaviour, looks unintended: a directory named like a file gets an extension in `list`,
        // while directories without a dot (and every directory in `search`) have the empty extension
        $this->actingAsAdmin();
        $this->seedDir('/x.d');
        $this->seedDir('/archive.tar');
        $this->seedDir('/plain');
        $this->seedFile('/f.txt', 'hello');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(4, $items);
        $this->assertListItem($this->item('archive.tar', '', 'dir', 0, 'tar'), $items[0]);
        $this->assertListItem($this->item('f.txt', '', 'file', 5, 'txt'), $items[1]);
        $this->assertListItem($this->item('plain', '', 'dir', 0, ''), $items[2]);
        $this->assertListItem($this->item('x.d', '', 'dir', 0, 'd'), $items[3]);
    }

    public function testListItemOfNestedDirectoryWithDotInNameKeepsDirnameAndHasExtension(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/sub/x.d');

        $items = $this->listResult($this->apiPost('list', ['path' => '/sub']));

        $this->assertCount(1, $items);
        $this->assertListItem($this->item('x.d', 'sub', 'dir', 0, 'd'), $items[0]);
    }

    public function testListPassesExactItemArrayToUrlThumbnailAndPreviewCallbacks(): void
    {
        $this->actingAsAdmin();
        $this->recordCallbackItems();
        $this->seedFile('/root.txt', 'abcd');
        $this->seedFile('/noext', 'abcde');
        $this->seedDir('/x.d');
        $this->seedDir('/plain');
        $this->seedFile('/sub/inner.txt', 'abc');

        $this->listResult($this->apiPost('list', ['path' => '/']));
        $this->listResult($this->apiPost('list', ['path' => '/sub']));

        $expected = [
            'noext' => $this->expectedCallbackItem('noext', 'file', 5, null),
            'plain' => $this->expectedCallbackItem('plain', 'dir', null, null),
            'root.txt' => $this->expectedCallbackItem('root.txt', 'file', 4, 'txt'),
            'sub' => $this->expectedCallbackItem('sub', 'dir', null, null),
            'sub/inner.txt' => $this->expectedCallbackItem('sub/inner.txt', 'file', 3, 'txt'),
            'x.d' => $this->expectedCallbackItem('x.d', 'dir', null, 'd'),
        ];
        // the literal shape of the three kinds of items, the timestamp is the mtime on disk
        $this->assertSame(
            ['type', 'path', 'timestamp', 'size', 'dirname', 'basename', 'extension', 'filename'],
            array_keys($expected['sub/inner.txt'])
        );
        $this->assertSame(['type' => 'file', 'path' => 'sub/inner.txt', 'size' => 3, 'dirname' => 'sub', 'basename' => 'inner.txt', 'extension' => 'txt', 'filename' => 'inner'], array_diff_key($expected['sub/inner.txt'], ['timestamp' => 0]));
        $this->assertSame(['type' => 'file', 'path' => 'noext', 'size' => 5, 'dirname' => '', 'basename' => 'noext', 'filename' => 'noext'], array_diff_key($expected['noext'], ['timestamp' => 0]));
        $this->assertSame(['type' => 'dir', 'path' => 'x.d', 'dirname' => '', 'basename' => 'x.d', 'extension' => 'd', 'filename' => 'x'], array_diff_key($expected['x.d'], ['timestamp' => 0]));
        foreach (['url', 'thumbnail', 'preview'] as $callback) {
            $this->assertCallbackItems($expected, $this->receivedBy($callback));
        }
    }

    public function testListAsPlainUserHidesItemsWithoutReadAccessAndRepairsRowsWithDefaultOwner(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9']);
        $this->seedFile('/pub/open.txt', '1');
        $this->seedHashmapRow('/pub/open.txt', 'file', ['access_owner' => '9', 'access_read' => '*']);
        $this->seedFile('/pub/secret.txt', '1');
        $this->seedHashmapRow('/pub/secret.txt', 'file', ['access_owner' => '9', 'access_read' => 'Staff']);
        $this->seedFile('/pub/inherits.txt', '1');
        $this->seedHashmapRow('/pub/inherits.txt', 'file', ['access_owner' => '9', 'access_read' => null]);
        $this->seedFile('/pub/norow.txt', '1');

        $items = $this->listResult($this->apiPost('list', ['path' => '/pub']));

        $this->assertSame(['inherits.txt', 'norow.txt', 'open.txt'], array_column($items, 'name'));
        $this->assertSame('1', $this->hashmapRow('/pub/norow.txt')['access_owner']);
        $this->assertSame('*', $this->hashmapRow('/pub/norow.txt')['access_read']);
        $this->assertSame('Staff', $this->hashmapRow('/pub/secret.txt')['access_read']);
    }

    public function testListAsPlainUserOfDirectoryWithoutReadAccessReturnsEmptyResult(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9', 'access_read' => 'Staff']);
        $this->seedFile('/pub/a.txt', '1');

        $response = $this->apiPost('list', ['path' => '/pub']);

        $this->assertStatus(200, $response);
        $this->assertSame(['result' => []], $response->json());
        // no row repaired for the hidden child
        $this->assertSame(['/pub'], array_keys($this->rows()));
    }

    // ------------------------------------------------------------------
    // createFolder
    // ------------------------------------------------------------------

    public function testCreateFolderCreatesDirectoryAndHashmapRowOwnedByActingUser(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/new']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/new' => null]);
        $this->assertRows(['/new' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderThenListShowsFolder(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/new');

        $items = $this->listResult($this->apiPost('list', ['path' => '/']));

        $this->assertCount(1, $items);
        $this->assertListItem($this->item('new', '', 'dir', 0, ''), $items[0]);
    }

    public function testCreateFolderWithMissingParentCreatesParentsOnDiskButOnlyLeafGetsRow(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/a/b/c']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/a' => null, '/a/b' => null, '/a/b/c' => null]);
        // NOTE: current behaviour, looks unintended: the implicitly created parents have no hashmap row
        $this->assertRows(['/a/b/c' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderThatExistsAsDirectoryReportsExistsAndMovesItsRowToRoot(): void
    {
        $this->actingAsAdmin();
        $this->seedDirViaApi('/d');

        $response = $this->apiPost('createFolder', ['newPath' => '/d']);

        $this->assertResult(false, 'exists', $response);
        $this->assertDisk(['/d' => null]);
        // NOTE: current behaviour, looks unintended: setAccess() runs also on failure with an empty new path,
        // so the existing row of /d is renamed to "/" (and its former owner is kept)
        $this->assertRows(['/' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderThatExistsAsDirectoryWithoutRowCreatesRowForExistingDirectory(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/d');

        $response = $this->apiPost('createFolder', ['newPath' => '/d']);

        $this->assertResult(false, 'exists', $response);
        $this->assertDisk(['/d' => null]);
        $this->assertRows(['/d' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderOverExistingFileReportsExistsAndKeepsFileContent(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/f', 'content');

        $response = $this->apiPost('createFolder', ['newPath' => '/f']);

        $this->assertResult(false, 'exists', $response);
        $this->assertDisk(['/f' => 'content']);
        // NOTE: current behaviour, looks unintended: a failed create adds a row (type file) for the existing file
        $this->assertRows(['/f' => $this->row('file', '7', (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderOverExistingFileWithRowMovesRowToRoot(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/f', 'xx');
        $this->seedHashmapRow('/f', 'file', [], 2);

        $response = $this->apiPost('createFolder', ['newPath' => '/f']);

        $this->assertResult(false, 'exists', $response);
        $this->assertDisk(['/f' => 'xx']);
        // NOTE: current behaviour, looks unintended: the row of the file is renamed to "/"
        $this->assertRows(['/' => $this->row('file', '2')]);
    }

    public function testCreateFolderDoesNotSlugOrNormaliseNames(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/My New Folder']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/My New Folder' => null]);
        $this->assertRows(['/My New Folder' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderKeepsUmlautsAndUpperCase(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/Übung ä']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/Übung ä' => null]);
        $this->assertRows(['/Übung ä' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderIgnoresSlugNamesSetting(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['slugNames' => true]);

        $response = $this->apiPost('createFolder', ['newPath' => '/Ab Cd']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/Ab Cd' => null]);
    }

    public function testCreateFolderWithoutLeadingSlashAndTrailingSlashKeepsTrailingSlashInRow(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => 'x/y/']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/x' => null, '/x/y' => null]);
        // NOTE: current behaviour, looks unintended: the row path keeps the trailing slash ("/x/y/")
        $this->assertRows(['/x/y/' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderWithDoubleLeadingSlashNormalisesRowPath(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '//z']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/z' => null]);
        $this->assertRows(['/z' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderForRootSucceedsAndCreatesRootRowWithoutType(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/']);

        $this->assertResult(true, '', $response);
        $this->assertDisk([]);
        // NOTE: current behaviour, looks unintended: a row for "/" with type NULL is created
        $this->assertRows(['/' => $this->row(null, null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderWithParentDirectoryTraversalIsRejectedWith500(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/..']);

        $this->assertStatus(500, $response);
        $this->assertDisk([]);
        $this->assertRows([]);
    }

    public function testCreateFolderWithInnerDotDotSegmentIsNotNormalisedInHashmapRow(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/a/../b']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/b' => null]);
        // NOTE: current behaviour, looks unintended: disk has /b but the hashmap row path is "/a/../b"
        $this->assertRows(['/a/../b' => $this->row('dir', null, (string)self::ADMIN_ID)]);
    }

    public function testCreateFolderMissingParameterReturns400(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', []);

        $this->assertStatus(400, $response);
        $this->assertDisk([]);
    }

    public function testCreateFolderAsPlainUserBelowWritableDirectoryIsOwnedByUser(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9']);

        $response = $this->apiPost('createFolder', ['newPath' => '/pub/sub']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/pub' => null, '/pub/sub' => null]);
        $this->assertRows([
            '/pub' => $this->row('dir', null, '9'),
            '/pub/sub' => $this->row('dir', null, '5'),
        ]);
    }

    public function testCreateFolderAsPlainUserWithoutUpdateAccessReportsNopermission(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9', 'access_update' => 'Staff']);

        $response = $this->apiPost('createFolder', ['newPath' => '/pub/sub']);

        $this->assertResult(false, 'nopermission', $response);
        $this->assertDisk(['/pub' => null]);
        $this->assertSame(['/pub'], array_keys($this->rows()));
    }

    public function testCreateFolderAsPlainUserBelowRootReportsNopermission(): void
    {
        $this->actingAsFileflyUser(5);

        $response = $this->apiPost('createFolder', ['newPath' => '/top']);

        $this->assertResult(false, 'nopermission', $response);
        $this->assertDisk([]);
        $this->assertRows([]);
    }

    // ------------------------------------------------------------------
    // rename
    // ------------------------------------------------------------------

    public function testRenameFileMovesContentAndRepairsRowBeforeRenamingIt(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/b.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/b.txt' => 'A']);
        $this->assertRows(['/b.txt' => $this->row('file', '1')]);
    }

    public function testRenameFileWithExistingRowKeepsRowDataAndChangesPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file', ['access_owner' => '7', 'access_read' => 'Staff'], 42);

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/b.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/b.txt' => 'A']);
        // size is not refreshed
        $this->assertRows(['/b.txt' => $this->row('file', '42', '7', 'Staff')]);
    }

    public function testRenameAcceptsPathsWithoutLeadingSlash(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');

        $response = $this->apiPost('rename', ['item' => 'a.txt', 'newItemPath' => 'b.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/b.txt' => 'A']);
        $this->assertRows(['/b.txt' => $this->row('file', '1')]);
    }

    public function testRenameDirectoryMovesChildrenOnDiskAndUpdatesChildRows(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/x.txt', 'X');
        $this->seedFile('/d/s/y.txt', 'Y');
        $this->seedHashmapRow('/d', 'dir');
        $this->seedHashmapRow('/d/x.txt', 'file');
        $this->seedHashmapRow('/d/s', 'dir');
        $this->seedHashmapRow('/d/s/y.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/d', 'newItemPath' => '/e']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/e' => null, '/e/x.txt' => 'X', '/e/s' => null, '/e/s/y.txt' => 'Y']);
        $this->assertRows([
            '/e' => $this->row('dir'),
            '/e/x.txt' => $this->row('file'),
            '/e/s' => $this->row('dir'),
            '/e/s/y.txt' => $this->row('file'),
        ]);
    }

    public function testRenameDirectoryAlsoRewritesRowsOfSiblingsWithSamePrefix(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/x.txt', 'X');
        $this->seedFile('/dd/z.txt', 'Z');
        $this->seedHashmapRow('/d', 'dir');
        $this->seedHashmapRow('/d/x.txt', 'file');
        $this->seedHashmapRow('/dd', 'dir');
        $this->seedHashmapRow('/dd/z.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/d', 'newItemPath' => '/e']);

        $this->assertResult(true, '', $response);
        // the unrelated directory /dd keeps its place on disk
        $this->assertDisk(['/e' => null, '/e/x.txt' => 'X', '/dd' => null, '/dd/z.txt' => 'Z']);
        // NOTE: current behaviour, looks unintended: the rows of /dd and /dd/z.txt match the prefix "/d" and are
        // rewritten to /ed and /ed/z.txt, so they no longer match the files on disk
        $this->assertRows([
            '/e' => $this->row('dir'),
            '/e/x.txt' => $this->row('file'),
            '/ed' => $this->row('dir'),
            '/ed/z.txt' => $this->row('file'),
        ]);
    }

    public function testRenameDirectoryDoesNotRepairRowsOfChildrenWithoutRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/x.txt', 'X');

        $response = $this->apiPost('rename', ['item' => '/d', 'newItemPath' => '/e']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/e' => null, '/e/x.txt' => 'X']);
        $this->assertRows(['/e' => $this->row('dir')]);
    }

    public function testRenameDirectoryUpdatesNestedSegmentWithSameNameInPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/a/x.txt', 'A');
        $this->seedHashmapRow('/a/a', 'dir');
        $this->seedHashmapRow('/a/a/x.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a/a', 'newItemPath' => '/a/b']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/a' => null, '/a/b' => null, '/a/b/x.txt' => 'A']);
        $this->assertRows(['/a/b' => $this->row('dir'), '/a/b/x.txt' => $this->row('file')]);
    }

    public function testRenameOntoExistingFileKeepsBothFilesAndRowsAndAnswers500(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedFile('/b.txt', 'B');
        $this->seedHashmapRow('/a.txt', 'file');
        $this->seedHashmapRow('/b.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/b.txt']);

        // NOTE: current behaviour: refusal is an unhandled exception (HTTP 500, no JSON result); a library
        // that overwrites silently would break this test on purpose
        $this->assertStatus(500, $response);
        $this->assertDisk(['/a.txt' => 'A', '/b.txt' => 'B']);
        $this->assertRows(['/a.txt' => $this->row('file'), '/b.txt' => $this->row('file')]);
    }

    public function testRenameOntoExistingFileWithoutRowsOnlyRepairsRowOfSource(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedFile('/b.txt', 'B');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/b.txt']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a.txt' => 'A', '/b.txt' => 'B']);
        $this->assertRows(['/a.txt' => $this->row('file', '1')]);
    }

    public function testRenameFileOntoExistingDirectoryKeepsFileAndDirectoryContentAndAnswers500(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedFile('/b/in.txt', 'I');
        $this->seedHashmapRow('/a.txt', 'file');
        $this->seedHashmapRow('/b', 'dir');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/b']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a.txt' => 'A', '/b' => null, '/b/in.txt' => 'I']);
        $this->assertRows(['/a.txt' => $this->row('file'), '/b' => $this->row('dir')]);
    }

    public function testRenameDirectoryOntoExistingDirectoryKeepsBothTreesAndAnswers500(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/x.txt', 'X');
        $this->seedFile('/b/y.txt', 'Y');
        $this->seedHashmapRow('/a', 'dir');
        $this->seedHashmapRow('/b', 'dir');

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/b']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a' => null, '/a/x.txt' => 'X', '/b' => null, '/b/y.txt' => 'Y']);
        $this->assertRows(['/a' => $this->row('dir'), '/b' => $this->row('dir')]);
    }

    public function testRenameDirectoryOntoExistingEmptyDirectoryKeepsBothAndAnswers500(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/x.txt', 'X');
        $this->seedDir('/b');
        $this->seedHashmapRow('/a', 'dir');
        $this->seedHashmapRow('/b', 'dir');

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/b']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a' => null, '/a/x.txt' => 'X', '/b' => null]);
        $this->assertRows(['/a' => $this->row('dir'), '/b' => $this->row('dir')]);
    }

    public function testRenameDirectoryOntoExistingFileKeepsBothAndAnswers500(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a/x.txt', 'X');
        $this->seedFile('/b.txt', 'B');
        $this->seedHashmapRow('/a', 'dir');
        $this->seedHashmapRow('/b.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/b.txt']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a' => null, '/a/x.txt' => 'X', '/b.txt' => 'B']);
        $this->assertRows(['/a' => $this->row('dir'), '/b.txt' => $this->row('file')]);
    }

    public function testRenameMissingItemAnswers500AndRepairCreatesRowWithoutType(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('rename', ['item' => '/nope.txt', 'newItemPath' => '/b.txt']);

        $this->assertStatus(500, $response);
        $this->assertDisk([]);
        // NOTE: current behaviour, looks unintended: the pre-check repair leaves a row with type NULL
        $this->assertRows(['/nope.txt' => $this->row(null)]);
    }

    public function testRenameMissingItemWithOrphanRowAnswers500AndKeepsRow(): void
    {
        $this->actingAsAdmin();
        $this->seedHashmapRow('/nope.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/nope.txt', 'newItemPath' => '/b.txt']);

        $this->assertStatus(500, $response);
        $this->assertDisk([]);
        $this->assertRows(['/nope.txt' => $this->row('file')]);
    }

    public function testRenameToSameNameAnswers500AndChangesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/a.txt']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a.txt' => 'A']);
        $this->assertRows(['/a.txt' => $this->row('file')]);
    }

    public function testRenameChangesFileExtension(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/a.md']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/a.md' => 'A']);
        $this->assertRows(['/a.md' => $this->row('file')]);
    }

    public function testRenameDoesNotSlugNewName(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/New Name Ä.TXT']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/New Name Ä.TXT' => 'A']);
        $this->assertRows(['/New Name Ä.TXT' => $this->row('file')]);
    }

    public function testRenameCanMoveFileToOtherDirectory(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedFile('/d/x', 'x');
        $this->seedHashmapRow('/a.txt', 'file');
        $this->seedHashmapRow('/d', 'dir');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/d/a.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/d' => null, '/d/a.txt' => 'A', '/d/x' => 'x']);
        $this->assertRows(['/d' => $this->row('dir'), '/d/a.txt' => $this->row('file')]);
    }

    public function testRenameToMissingParentCreatesParentsOnDiskWithoutRows(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/new/dir/a.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/new' => null, '/new/dir' => null, '/new/dir/a.txt' => 'A']);
        $this->assertRows(['/new/dir/a.txt' => $this->row('file')]);
    }

    public function testRenameFileRewritesRowOfSiblingWithSamePrefix(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedFile('/a.txt.bak', 'B');
        $this->seedHashmapRow('/a.txt', 'file');
        $this->seedHashmapRow('/a.txt.bak', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/c.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/c.txt' => 'A', '/a.txt.bak' => 'B']);
        // NOTE: current behaviour, looks unintended: the prefix match "/a.txt%" also rewrites the row of
        // "/a.txt.bak" to "/c.txt.bak" although the file stays on disk
        $this->assertRows(['/c.txt' => $this->row('file'), '/c.txt.bak' => $this->row('file')]);
    }

    public function testRenameFileToDeniedExtensionIsRejectedByNameValidator(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deniedExtensions' => ['php']]);
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/a.php']);

        $this->assertResult(false, 'File type not allowed', $response);
        $this->assertDisk(['/a.txt' => 'A']);
        $this->assertRows(['/a.txt' => $this->row('file')]);
    }

    public function testRenameFileToExtensionOutsideAllowedListIsRejected(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['allowedExtensions' => ['txt']]);
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/noext']);

        $this->assertResult(false, 'File type not allowed', $response);
        $this->assertDisk(['/a.txt' => 'A']);
    }

    public function testRenameFileToInvalidNameIsRejectedByNameValidatorEvenWithoutConfiguredRules(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiPost('rename', ['item' => '/a.txt', 'newItemPath' => '/...']);

        $this->assertResult(false, 'Invalid file name', $response);
        $this->assertDisk(['/a.txt' => 'A']);
        $this->assertRows(['/a.txt' => $this->row('file')]);
    }

    public function testRenameDirectoryToDeniedExtensionBypassesNameValidator(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deniedExtensions' => ['php']]);
        $this->seedFile('/a/x', 'A');
        $this->seedHashmapRow('/a', 'dir');

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/a.php']);

        // NOTE: current behaviour, looks unintended: the file name check applies to files only
        $this->assertResult(true, '', $response);
        $this->assertDisk(['/a.php' => null, '/a.php/x' => 'A']);
        $this->assertRows(['/a.php' => $this->row('dir')]);
    }

    public function testRenameMissingParameterReturns400(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('rename', ['item' => '/a']);

        $this->assertStatus(400, $response);
    }

    public function testRenameRootAnswers500AndCreatesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'A');

        $response = $this->apiPost('rename', ['item' => '/', 'newItemPath' => '/x']);

        $this->assertStatus(500, $response);
        $this->assertDisk(['/a.txt' => 'A']);
        $this->assertRows([]);
    }

    public function testRenameAsPlainUserWithoutUpdateAccessReportsPermissionDenied(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9']);
        $this->seedFile('/pub/a.txt', 'A');
        $this->seedHashmapRow('/pub/a.txt', 'file', ['access_owner' => '9', 'access_update' => 'Staff']);

        $response = $this->apiPost('rename', ['item' => '/pub/a.txt', 'newItemPath' => '/pub/c.txt']);

        $this->assertResult(false, 'You are not allowed to edit this file', $response);
        $this->assertDisk(['/pub' => null, '/pub/a.txt' => 'A']);
        $this->assertRows([
            '/pub' => $this->row('dir', null, '9'),
            '/pub/a.txt' => $this->row('file', null, '9', '*', 'Staff'),
        ]);
    }

    public function testRenameAsOwnerWithoutUpdateRoleSucceeds(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9']);
        $this->seedFile('/pub/b.txt', 'B');
        $this->seedHashmapRow('/pub/b.txt', 'file', ['access_owner' => '5', 'access_update' => 'Staff']);

        $response = $this->apiPost('rename', ['item' => '/pub/b.txt', 'newItemPath' => '/pub/d.txt']);

        $this->assertResult(true, '', $response);
        $this->assertDisk(['/pub' => null, '/pub/d.txt' => 'B']);
        $this->assertRows([
            '/pub' => $this->row('dir', null, '9'),
            '/pub/d.txt' => $this->row('file', null, '5', '*', 'Staff'),
        ]);
    }

    // ------------------------------------------------------------------
    // search
    // ------------------------------------------------------------------

    public function testSearchFindsFilesByPathSubstringWithFullResultItemShape(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/docs/report.txt', '1');
        $this->seedHashmapRow('/docs/report.txt', 'file');

        $response = $this->apiGet('search', ['q' => 'report']);

        $this->assertStatus(200, $response);
        $this->assertSame([[
            'path' => '/docs/report.txt',
            'type' => 'file',
            'extension' => 'txt',
            'id' => '/docs/report.txt',
            'thumbnail' => '',
            'mime' => '',
        ]], $response->json());
    }

    public function testSearchMatchesCaseInsensitivelyAndAlsoViaPost(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();
        $expected = ['/docs/Report-2.PDF', '/docs/report.txt', '/ghost-report.txt'];

        $this->assertSame($expected, $this->searchPaths($this->apiGet('search', ['q' => 'report'])));
        $this->assertSame($expected, $this->searchPaths($this->apiGet('search', ['q' => 'REPORT'])));
        $this->assertSame($expected, $this->searchPaths($this->apiPost('search', ['q' => 'report'])));
    }

    public function testSearchKeepsExtensionCaseOfPath(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => 'Report-2']);

        $this->assertStatus(200, $response);
        $this->assertSame('PDF', $response->json()[0]['extension']);
    }

    public function testSearchWithOnlyFilesOffReturnsDirectoriesWithEmptyExtensionAlsoWithDotInName(): void
    {
        // NOTE: current behaviour, looks unintended: unlike `list`, `search` never sets an extension for directories
        $this->actingAsAdmin();
        $this->seedDir('/x.d');
        $this->seedDir('/archive.tar');
        $this->seedDir('/plain');
        $this->seedFile('/x.txt', '1');
        foreach (['/x.d', '/archive.tar', '/plain'] as $path) {
            $this->seedHashmapRow($path, 'dir');
        }
        $this->seedHashmapRow('/x.txt', 'file');

        $response = $this->apiGet('search', ['q' => 'a', 'only_files' => 0]);
        $this->assertStatus(200, $response);
        $hits = $response->json();
        usort($hits, static function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        $this->assertSame([
            ['path' => '/archive.tar', 'type' => 'dir', 'extension' => '', 'id' => '/archive.tar', 'thumbnail' => '', 'mime' => ''],
            ['path' => '/plain', 'type' => 'dir', 'extension' => '', 'id' => '/plain', 'thumbnail' => '', 'mime' => ''],
        ], $hits);

        $response = $this->apiGet('search', ['q' => 'x.', 'only_files' => 0]);
        $this->assertStatus(200, $response);
        $hits = $response->json();
        usort($hits, static function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        $this->assertSame([
            ['path' => '/x.d', 'type' => 'dir', 'extension' => '', 'id' => '/x.d', 'thumbnail' => '', 'mime' => ''],
            ['path' => '/x.txt', 'type' => 'file', 'extension' => 'txt', 'id' => '/x.txt', 'thumbnail' => '', 'mime' => ''],
        ], $hits);
    }

    public function testSearchPassesOnlyPathTypeExtensionAndIdToTheThumbnailCallback(): void
    {
        // the url and preview callbacks are not called by `search`
        $this->actingAsAdmin();
        $this->recordCallbackItems();
        $this->seedFile('/sub/inner.txt', 'abc');
        $this->seedFile('/noext', 'abcde');
        $this->seedDir('/x.d');
        foreach (['/sub/inner.txt' => 'file', '/noext' => 'file', '/x.d' => 'dir'] as $path => $type) {
            $this->seedHashmapRow($path, $type);
        }

        $response = $this->apiGet('search', ['q' => '', 'only_files' => 0]);

        $this->assertStatus(200, $response);
        $this->assertSame([], self::$callbackItems['url']);
        $this->assertSame([], self::$callbackItems['preview']);
        $this->assertSame([
            '/noext' => ['path' => '/noext', 'type' => 'file', 'extension' => '', 'id' => '/noext'],
            '/sub/inner.txt' => ['path' => '/sub/inner.txt', 'type' => 'file', 'extension' => 'txt', 'id' => '/sub/inner.txt'],
            '/x.d' => ['path' => '/x.d', 'type' => 'dir', 'extension' => '', 'id' => '/x.d'],
        ], $this->receivedBy('thumbnail'));
    }

    public function testSearchWithoutMatchReturnsEmptyList(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => 'zzz']);

        $this->assertStatus(200, $response);
        $this->assertSame([], $response->json());
    }

    public function testSearchMissingQueryReturns400(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('search', []);

        $this->assertStatus(400, $response);
    }

    public function testSearchWithEmptyQueryReturnsAllFiles(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => '']));

        $this->assertSame(
            ['/docs/Report-2.PDF', '/docs/report.txt', '/ghost-report.txt', '/img/100%.png', '/img/pic_1.png'],
            $paths
        );
    }

    public function testSearchListsOrphanHashmapRowsWithoutFileOnDisk(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'ghost']));

        // NOTE: current behaviour, looks unintended: search is based on hashmap rows only, the file need not exist
        $this->assertSame(['/ghost-report.txt'], $paths);
    }

    public function testSearchReturnsOnlyFilesByDefault(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => 'reports']);

        $this->assertStatus(200, $response);
        $this->assertSame([], $response->json());
    }

    public function testSearchOnlyFilesZeroAlsoReturnsDirectoriesWithEmptyExtension(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => 'reports', 'only_files' => 0]);

        $this->assertStatus(200, $response);
        $this->assertSame([[
            'path' => '/reports',
            'type' => 'dir',
            'extension' => '',
            'id' => '/reports',
            'thumbnail' => '',
            'mime' => '',
        ]], $response->json());
    }

    public function testSearchOnlyFilesZeroMixesFilesAndDirectories(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'report', 'only_files' => 0]));

        $this->assertSame(['/docs/Report-2.PDF', '/docs/report.txt', '/ghost-report.txt', '/reports'], $paths);
    }

    public function testSearchLimitCapsNumberOfResults(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => 'report', 'limit' => 1]);

        $this->assertStatus(200, $response);
        $this->assertCount(1, $response->json());
        $this->assertContains(
            $response->json()[0]['path'],
            ['/docs/Report-2.PDF', '/docs/report.txt', '/ghost-report.txt']
        );
    }

    public function testSearchLimitZeroMeansNoLimit(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'report', 'limit' => 0]));

        $this->assertCount(3, $paths);
    }

    public function testSearchQueryLimitCapsDatabaseRowsBeforePermissionCheck(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => 'report', 'q_limit' => 1]);

        $this->assertStatus(200, $response);
        $this->assertCount(1, $response->json());
    }

    public function testSearchBasePathRestrictsResultsToPathPrefix(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'report', 'basePath' => '/docs']));

        $this->assertSame(['/docs/Report-2.PDF', '/docs/report.txt'], $paths);
    }

    public function testSearchBasePathDoesNotEscapeLikeWildcards(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'report', 'basePath' => '/d_cs']));

        // NOTE: current behaviour, looks unintended: "_" in basePath acts as LIKE wildcard and matches "o"
        $this->assertSame(['/docs/Report-2.PDF', '/docs/report.txt'], $paths);
    }

    public function testSearchEscapesPercentSignInQuery(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $this->assertSame(['/img/100%.png'], $this->searchPaths($this->apiGet('search', ['q' => '100%'])));
        // a lone wildcard is literal too and only matches the path containing a percent sign
        $this->assertSame(['/img/100%.png'], $this->searchPaths($this->apiGet('search', ['q' => '%'])));
    }

    public function testSearchTreatsUnderscoreInQueryLiterally(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();
        $this->seedFile('/img/picX1.png', '1');
        $this->seedHashmapRow('/img/picX1.png', 'file');

        $this->assertSame(['/img/pic_1.png'], $this->searchPaths($this->apiGet('search', ['q' => 'pic_'])));
    }

    public function testSearchQueryWithQuotesAndSqlFragmentsMatchesNothingAndIsHarmless(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $response = $this->apiGet('search', ['q' => "'\"; DROP TABLE filefly_hashmap; --"]);

        $this->assertStatus(200, $response);
        $this->assertSame([], $response->json());
        $this->assertCount(7, $this->rows());
    }

    public function testSearchQueryMatchesSlashInPath(): void
    {
        $this->actingAsAdmin();
        $this->seedSearchFixtures();

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'docs/']));

        $this->assertSame(['/docs/Report-2.PDF', '/docs/report.txt'], $paths);
    }

    public function testSearchUsesThumbnailCallback(): void
    {
        $this->actingAsAdmin();
        $this->configureModule([
            'thumbnailCallback' => static function ($item) {
                return 't:' . $item['path'];
            },
        ]);
        $this->seedFile('/a.txt', '1');
        $this->seedHashmapRow('/a.txt', 'file');

        $response = $this->apiGet('search', ['q' => 'a.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame('t:/a.txt', $response->json()[0]['thumbnail']);
    }

    public function testSearchAsPlainUserHidesItemsWithoutReadAccess(): void
    {
        $this->actingAsFileflyUser(5);
        $this->seedDir('/pub');
        $this->seedHashmapRow('/pub', 'dir', ['access_owner' => '9']);
        $this->seedFile('/pub/open.txt', '1');
        $this->seedHashmapRow('/pub/open.txt', 'file', ['access_owner' => '9', 'access_read' => '*']);
        $this->seedFile('/pub/secret.txt', '1');
        $this->seedHashmapRow('/pub/secret.txt', 'file', ['access_owner' => '9', 'access_read' => 'Staff']);
        $this->seedFile('/pub/inherits.txt', '1');
        $this->seedHashmapRow('/pub/inherits.txt', 'file', ['access_owner' => '9', 'access_read' => null]);
        $this->seedFile('/pub/norow.txt', '1');

        $paths = $this->searchPaths($this->apiGet('search', ['q' => 'txt']));

        // search never repairs and only knows rows, so norow.txt is not found
        $this->assertSame(['/pub/inherits.txt', '/pub/open.txt'], $paths);
        $this->assertNull($this->hashmapRow('/pub/norow.txt'));
    }
}
