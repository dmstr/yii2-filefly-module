<?php
// file generated with AI assistance: Claude Code - [2026-11-12 20:30:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\events\FileEvent;
use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;
use Yii;
use yii\db\Query;
use yii\log\Logger;

/**
 * Names that the database collation considers equal to the path of an existing item (case,
 * accents, trailing spaces under the MariaDB/MySQL collations of the migrated table) while the
 * storage keeps them apart.
 *
 * A hashmap row belongs to a path only if the stored path is byte for byte equal to it. The tests
 * hold on every database: where the database compares byte-wise (sqlite, PostgreSQL) the two
 * names are independent items with their own rows; where it does not (MariaDB, MySQL) the second
 * name is refused like an existing target, or stays without row. In both cases the row of the
 * existing item stays byte for byte unchanged and the acting user cannot read, list, change the
 * permissions of or delete that item.
 *
 * The expected result of the colliding request is chosen by databaseConsidersEqual(); a pair the
 * storage cannot keep apart (case on macOS) is skipped by requireStorageKeepsApart().
 *
 * Acting non-admin user id 5 with FileflyDefault, `filefly_api` and RoleA; the existing item
 * belongs to id 7 and is restricted to the role `Secret` the acting user lacks.
 */
class CollationCollisionTest extends ApiTestCase
{
    /** precomposed (NFC) small a with diaeresis, U+00E4 */
    private const AE = "\u{00E4}";

    /** precomposed (NFC) small o with diaeresis, U+00F6 */
    private const OE = "\u{00F6}";

    protected function setUp(): void
    {
        parent::setUp();
        // the guard tests read the logged messages after the requests; never flush them away
        Yii::getLogger()->flushInterval = PHP_INT_MAX;
    }

    // ------------------------------------------------------------------
    // upload
    // ------------------------------------------------------------------

    /**
     * existing item, uploaded name, module properties
     */
    public function uploadCollisionProvider(): array
    {
        return [
            'case (slugged upload)' => ['/Report.txt', 'report.txt', []],
            'accent' => ['/a.txt', self::AE . '.txt', ['slugNames' => false]],
            'trailing space' => ['/a.txt', 'a.txt ', ['slugNames' => false]],
        ];
    }

    /**
     * @dataProvider uploadCollisionProvider
     */
    public function testUploadOfCollidingNameLeavesTheExistingItemAlone(string $victim, string $name, array $module): void
    {
        $this->requireStorageKeepsApart(basename($victim), $name);
        $this->configureModule($module);
        $this->actAsApiUser();
        $this->seedVictimFile($victim);
        $before = $this->hashmapRows();
        $log = [];
        $this->recordEvents([FileEvent::EVENT_UPLOAD_SUCCESS, FileEvent::EVENT_UPLOAD_ERROR], $log);

        $response = $this->apiUpload('/', [$name => 'MINE']);

        if ($this->databaseConsidersEqual($victim, '/' . $name)) {
            $this->assertStatus(400, $response);
            $this->assertSame(
                ['result' => ['success' => false, 'error' => 'File already exists', 'uploadedFiles' => []]],
                $response->json()
            );
            $this->assertSame([[FileEvent::EVENT_UPLOAD_ERROR, null, 'file_already_exists']], $log);
            $this->assertSame([$victim => 'VICTIM'], $this->disk());
            $this->assertSame($before, $this->hashmapRows());
            $expectedListing = [];
        } else {
            $this->assertApiSuccess($response);
            $this->assertSame([$name], $response->json()['result']['uploadedFiles']);
            $this->assertSame([FileEvent::EVENT_UPLOAD_SUCCESS], array_column($log, 0));
            $this->assertFileOnDisk('/' . $name, 'MINE');
            $this->assertRowsKept($before, ['/' . $name]);
            $this->assertAccess('/' . $name, 'file', (string)self::USER_ID, '*', '*', '*');
            $expectedListing = [$name];
        }

        $this->assertAttackerLockedOut($victim, '/', $expectedListing);
        $this->assertFileOnDisk($victim, 'VICTIM');
        $this->assertRowsKept($before, $expectedListing === [] ? [] : ['/' . $name]);
    }

    // ------------------------------------------------------------------
    // createFolder
    // ------------------------------------------------------------------

    /**
     * existing folder, new folder (both below `/p`)
     */
    public function folderCollisionProvider(): array
    {
        return [
            'case' => ['/p/Docs', '/p/docs'],
            'accent' => ['/p/ordner', '/p/' . self::OE . 'rdner'],
        ];
    }

    /**
     * @dataProvider folderCollisionProvider
     */
    public function testCreateFolderOfCollidingNameAsNonAdminKeepsTheExistingFolderAndItsChildren(string $victim, string $newPath): void
    {
        $this->requireStorageKeepsApart(basename($victim), basename($newPath));
        $this->actAsApiUser();
        $this->seedFolderTree($victim);
        $before = $this->hashmapRows();
        $log = [];
        $this->recordEvents([FileEvent::EVENT_CREATE_FOLDER_SUCCESS, FileEvent::EVENT_CREATE_FOLDER_ERROR], $log);

        $response = $this->apiPost('createFolder', ['newPath' => $newPath]);

        $collides = $this->databaseConsidersEqual($victim, $newPath);
        $this->assertCreateFolderOutcome($collides, $victim, $newPath, $before, $response, $log);
        $this->assertAttackerLockedOut($victim, '/p', $collides ? [] : [basename($newPath)]);
        $this->assertDirOnDisk($victim);
        $this->assertFileOnDisk($victim . '/inner.txt', 'VICTIM');
        $this->assertRowsKept($before, $collides ? [] : [$newPath]);
    }

    /**
     * @dataProvider folderCollisionProvider
     */
    public function testCreateFolderOfCollidingNameAsAdminKeepsTheExistingFolderAndItsChildren(string $victim, string $newPath): void
    {
        $this->requireStorageKeepsApart(basename($victim), basename($newPath));
        $this->actingAsAdmin();
        $this->seedFolderTree($victim);
        $before = $this->hashmapRows();
        $log = [];
        $this->recordEvents([FileEvent::EVENT_CREATE_FOLDER_SUCCESS, FileEvent::EVENT_CREATE_FOLDER_ERROR], $log);

        $response = $this->apiPost('createFolder', ['newPath' => $newPath]);

        $collides = $this->databaseConsidersEqual($victim, $newPath);
        $this->assertCreateFolderOutcome($collides, $victim, $newPath, $before, $response, $log);
        if (!$collides) {
            $this->assertAccess($newPath, 'dir', (string)self::ADMIN_ID, '*', '*', '*');
        }
    }

    // ------------------------------------------------------------------
    // copy, move, rename
    // ------------------------------------------------------------------

    public function testCopyOfOwnFileOntoCollidingNameIsRefusedOrIndependent(): void
    {
        $this->actAsApiUser();
        $this->seedVictimFile('/a.txt');
        $own = '/own/' . self::AE . '.txt';
        $this->seedOwnItem('/own', 'dir');
        $this->seedOwnItem($own, 'file', 'MINE');
        $before = $this->hashmapRows();
        $log = [];
        $this->recordEvents([FileEvent::EVENT_AFTER_COPY, FileEvent::EVENT_COPY_SUCCESS, FileEvent::EVENT_COPY_ERROR], $log);

        $response = $this->apiPost('copy', ['newPath' => '/', 'items' => [$own]]);

        $target = '/' . self::AE . '.txt';
        $collides = $this->databaseConsidersEqual('/a.txt', $target);
        if ($collides) {
            $this->assertResult(false, 'File already exists', $response);
            $this->assertSame([[FileEvent::EVENT_COPY_ERROR, null, 'file_already_exists']], $log);
            $this->assertNotOnDisk($target);
            $this->assertSame($before, $this->hashmapRows());
        } else {
            $this->assertResult(true, '', $response);
            $this->assertSame([FileEvent::EVENT_AFTER_COPY, FileEvent::EVENT_COPY_SUCCESS], array_column($log, 0));
            $this->assertFileOnDisk($target, 'MINE');
            $this->assertRowsKept($before, [$target]);
            $this->assertAccess($target, 'file', (string)self::USER_ID, '*', '*', '*');
        }
        $this->assertFileOnDisk($own, 'MINE');

        $this->assertAttackerLockedOut('/a.txt', '/', $collides ? ['own'] : ['own', self::AE . '.txt']);
        $this->assertFileOnDisk('/a.txt', 'VICTIM');
        $this->assertRowsKept($before, $collides ? [] : [$target]);
    }

    public function testMoveOfOwnFileOntoCollidingNameIsRefusedOrIndependent(): void
    {
        $this->actAsApiUser();
        $this->seedVictimFile('/a.txt');
        $own = '/own/' . self::AE . '.txt';
        $this->seedOwnItem('/own', 'dir');
        $this->seedOwnItem($own, 'file', 'MINE');
        $before = $this->hashmapRows();
        $log = [];
        $this->recordEvents([FileEvent::EVENT_AFTER_MOVE, FileEvent::EVENT_MOVE_SUCCESS, FileEvent::EVENT_MOVE_ERROR], $log);

        $response = $this->apiPost('move', ['newPath' => '/', 'items' => [$own]]);

        $target = '/' . self::AE . '.txt';
        $collides = $this->databaseConsidersEqual('/a.txt', $target);
        if ($collides) {
            $this->assertResult(false, 'File already exists', $response);
            $this->assertSame([[FileEvent::EVENT_MOVE_ERROR, null, 'file_already_exists']], $log);
            $this->assertFileOnDisk($own, 'MINE');
            $this->assertNotOnDisk($target);
            $this->assertSame($before, $this->hashmapRows());
        } else {
            $this->assertResult(true, '', $response);
            $this->assertSame([FileEvent::EVENT_AFTER_MOVE, FileEvent::EVENT_MOVE_SUCCESS], array_column($log, 0));
            $this->assertFileOnDisk($target, 'MINE');
            $this->assertNotOnDisk($own);
            $this->assertSame(['/a.txt', '/own', $target], $this->rowPaths());
            $this->assertAccess($target, 'file', (string)self::USER_ID, 'RoleA', 'RoleA', 'RoleA');
        }

        $this->assertAttackerLockedOut('/a.txt', '/', $collides ? ['own'] : ['own', self::AE . '.txt']);
        $this->assertFileOnDisk('/a.txt', 'VICTIM');
        $this->assertSame($this->rowOf($before, '/a.txt'), $this->hashmapRow('/a.txt'));
    }

    public function testMoveOfOwnDirectoryOntoCollidingDirectoryNameIsRefusedOrIndependent(): void
    {
        $this->actAsApiUser();
        $victim = '/ordner';
        $this->seedFolderTree('/p' . $victim);
        $own = '/own/' . self::OE . 'rdner';
        $this->seedOwnItem('/own', 'dir');
        $this->seedOwnItem($own, 'dir');
        $this->seedOwnItem($own . '/mine.txt', 'file', 'MINE');
        $before = $this->hashmapRows();

        $response = $this->apiPost('move', ['newPath' => '/p', 'items' => [$own]]);

        $target = '/p/' . self::OE . 'rdner';
        $collides = $this->databaseConsidersEqual('/p' . $victim, $target);
        if ($collides) {
            $this->assertResult(false, 'File already exists', $response);
            $this->assertFileOnDisk($own . '/mine.txt', 'MINE');
            $this->assertNotOnDisk($target);
            $this->assertSame($before, $this->hashmapRows());
        } else {
            $this->assertResult(true, '', $response);
            $this->assertFileOnDisk($target . '/mine.txt', 'MINE');
            $this->assertNotOnDisk($own);
            $this->assertAccess($target, 'dir', (string)self::USER_ID, 'RoleA', 'RoleA', 'RoleA');
            $this->assertAccess($target . '/mine.txt', 'file', (string)self::USER_ID, 'RoleA', 'RoleA', 'RoleA');
            $this->assertNoHashmapRow($own);
        }

        $this->assertAttackerLockedOut('/p' . $victim, '/p', $collides ? [] : [self::OE . 'rdner']);
        $this->assertFileOnDisk('/p' . $victim . '/inner.txt', 'VICTIM');
        foreach (['/p', '/p' . $victim, '/p' . $victim . '/inner.txt'] as $path) {
            $this->assertSame($this->rowOf($before, $path), $this->hashmapRow($path), "row of $path");
        }
    }

    public function testRenameOfOwnFileToCollidingNameIsRefusedOrIndependent(): void
    {
        $this->actAsApiUser();
        $this->seedVictimFile('/a.txt');
        $this->seedOwnItem('/b.txt', 'file', 'MINE');
        $before = $this->hashmapRows();
        $log = [];
        $this->recordEvents([
            FileEvent::EVENT_BEFORE_RENAME,
            FileEvent::EVENT_AFTER_RENAME,
            FileEvent::EVENT_RENAME_SUCCESS,
            FileEvent::EVENT_RENAME_ERROR,
        ], $log);

        $target = '/' . self::AE . '.txt';
        $response = $this->apiPost('rename', ['item' => '/b.txt', 'newItemPath' => $target]);

        $collides = $this->databaseConsidersEqual('/a.txt', $target);
        if ($collides) {
            $this->assertResult(false, 'File already exists', $response);
            $this->assertSame([
                [FileEvent::EVENT_BEFORE_RENAME, '/b.txt', null],
                [FileEvent::EVENT_RENAME_ERROR, null, 'file_already_exists'],
            ], $log);
            $this->assertFileOnDisk('/b.txt', 'MINE');
            $this->assertNotOnDisk($target);
            $this->assertSame($before, $this->hashmapRows());
        } else {
            $this->assertResult(true, '', $response);
            $this->assertSame(
                [FileEvent::EVENT_BEFORE_RENAME, FileEvent::EVENT_AFTER_RENAME, FileEvent::EVENT_RENAME_SUCCESS],
                array_column($log, 0)
            );
            $this->assertFileOnDisk($target, 'MINE');
            $this->assertSame(['/a.txt', $target], $this->rowPaths());
            $this->assertAccess($target, 'file', (string)self::USER_ID, 'RoleA', 'RoleA', 'RoleA');
        }

        $this->assertAttackerLockedOut('/a.txt', '/', $collides ? ['b.txt'] : [self::AE . '.txt']);
        $this->assertFileOnDisk('/a.txt', 'VICTIM');
        $this->assertSame($this->rowOf($before, '/a.txt'), $this->hashmapRow('/a.txt'));
    }

    public function testRenameOfOwnFileToCaseVariantOfItsOwnNameMovesItsRowOnEveryDatabase(): void
    {
        $this->requireStorageKeepsApart('mine.txt', 'Mine.txt');
        $this->actAsApiUser();
        $this->seedOwnItem('/mine.txt', 'file', 'MINE');

        $response = $this->apiPost('rename', ['item' => '/mine.txt', 'newItemPath' => '/Mine.txt']);

        // the only row the database considers equal is the item's own row, which moves with it
        $this->assertResult(true, '', $response);
        $this->assertSame(['/Mine.txt' => 'MINE'], $this->disk());
        $this->assertSame(['/Mine.txt'], $this->rowPaths());
        $this->assertAccess('/Mine.txt', 'file', (string)self::USER_ID, 'RoleA', 'RoleA', 'RoleA');
    }

    // ------------------------------------------------------------------
    // self-heal
    // ------------------------------------------------------------------

    /**
     * existing item with row, unindexed file next to it
     */
    public function selfHealCollisionProvider(): array
    {
        return [
            'case' => ['/Report.txt', '/report.txt'],
            'accent' => ['/a.txt', '/' . self::AE . '.txt'],
        ];
    }

    /**
     * @dataProvider selfHealCollisionProvider
     */
    public function testListAsNonAdminCreatesNoRowForAnUnindexedCollidingFile(string $victim, string $unindexed): void
    {
        $this->requireStorageKeepsApart(basename($victim), basename($unindexed));
        $this->actAsApiUser();
        $this->seedVictimFile($victim);
        $this->seedFile($unindexed, 'UNINDEXED');
        $before = $this->hashmapRows();

        $list = $this->apiPost('list', ['path' => '/']);

        if ($this->databaseConsidersEqual($victim, $unindexed)) {
            // the unindexed file has no row of its own, so it is hidden like every item without row
            $this->assertSame([], $this->listedNames($list));
            $this->assertSame($before, $this->hashmapRows());
            $this->assertSame([], $this->accessErrorsLogged());
            $expectedListing = [];
        } else {
            $this->assertSame([basename($unindexed)], $this->listedNames($list));
            $this->assertRowsKept($before, [$unindexed]);
            $this->assertAccess($unindexed, 'file', '1', '*', '*', '*');
            $expectedListing = [basename($unindexed)];
        }

        $this->assertAttackerLockedOut($victim, '/', $expectedListing);
        $this->assertFileOnDisk($victim, 'VICTIM');
        $this->assertFileOnDisk($unindexed, 'UNINDEXED');
        $this->assertRowsKept($before, $expectedListing === [] ? [] : [$unindexed]);
    }

    /**
     * @dataProvider selfHealCollisionProvider
     */
    public function testListAsAdminCreatesNoRowForAnUnindexedCollidingFile(string $victim, string $unindexed): void
    {
        $this->requireStorageKeepsApart(basename($victim), basename($unindexed));
        $this->actingAsAdmin();
        $this->seedVictimFile($victim);
        $this->seedFile($unindexed, 'UNINDEXED');
        $before = $this->hashmapRows();

        $first = $this->apiPost('list', ['path' => '/']);
        $second = $this->apiPost('list', ['path' => '/']);

        $names = [basename($victim), basename($unindexed)];
        sort($names);
        $this->assertSame($names, $this->listedNames($first));
        $this->assertSame($names, $this->listedNames($second));
        if ($this->databaseConsidersEqual($victim, $unindexed)) {
            $this->assertSame($before, $this->hashmapRows());
            $this->assertSame([], $this->accessErrorsLogged());
        } else {
            $this->assertRowsKept($before, [$unindexed]);
            $this->assertAccess($unindexed, 'file', '1', '*', '*', '*');
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Whether the database considers the two paths equal in the `path` column of the hashmap table
     * (asked with a probe row of another component, removed again). The single capability the
     * expected results branch on.
     */
    private function databaseConsidersEqual(string $existingPath, string $otherPath): bool
    {
        $db = Yii::$app->db;
        $component = 'collation-probe';
        $now = date('Y-m-d H:i:s');
        $db->createCommand()->insert('filefly_hashmap', [
            'component' => $component,
            'path' => $existingPath,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        try {
            $count = (int)(new Query())
                ->from('filefly_hashmap')
                ->where(['component' => $component, 'path' => $otherPath])
                ->count('*', $db);
        } finally {
            $db->createCommand()->delete('filefly_hashmap', ['component' => $component])->execute();
        }
        return $count > 0;
    }

    /**
     * Skips the test if the storage root cannot hold both names side by side as two items with
     * exactly these names (case-insensitive file systems such as the macOS default, or a file
     * system that refuses or rewrites one of the names)
     */
    private function requireStorageKeepsApart(string $nameA, string $nameB): void
    {
        $dir = $this->fsRoot . '/collation-probe';
        mkdir($dir);
        $written = @file_put_contents($dir . '/' . $nameA, 'A') !== false
            && @file_put_contents($dir . '/' . $nameB, 'B') !== false;
        $names = array_values(array_diff((array)scandir($dir), ['.', '..']));
        $apart = $written
            && count($names) === 2
            && in_array($nameA, $names, true)
            && in_array($nameB, $names, true)
            && file_get_contents($dir . '/' . $nameA) === 'A';
        foreach ($names as $name) {
            unlink($dir . '/' . $name);
        }
        rmdir($dir);
        if (!$apart) {
            $this->markTestSkipped(sprintf(
                'The file system of the storage root cannot keep "%s" and "%s" apart (for example a case-insensitive file system)',
                $nameA,
                $nameB
            ));
        }
    }

    /**
     * File of user 7 on disk with a row restricted to `Secret`
     */
    private function seedVictimFile(string $path): void
    {
        $this->seedFile($path, 'VICTIM');
        $this->seedRow($path, 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 6);
    }

    /**
     * Folder `/p` the acting user may update (with a row of user 7), and in it a folder of user 7
     * restricted to `Secret` with one file
     */
    private function seedFolderTree(string $victim): void
    {
        if ($this->hashmapRow('/p') === null) {
            $this->seedDir('/p');
            $this->seedRow('/p', 'dir', self::OTHER_ID, 'RoleA', 'RoleA', 'Secret');
        }
        $this->seedDir($victim);
        $this->seedRow($victim, 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedFile($victim . '/inner.txt', 'VICTIM');
        $this->seedRow($victim . '/inner.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 6);
    }

    /**
     * Item of the acting user (row owner 5, RoleA in every access column); a file gets $content
     */
    private function seedOwnItem(string $path, string $type, string $content = ''): void
    {
        if ($type === 'dir') {
            $this->seedDir($path);
        } else {
            $this->seedFile($path, $content);
        }
        $this->seedRow($path, $type, (string)self::USER_ID, 'RoleA', 'RoleA', 'RoleA', $type === 'dir' ? null : strlen($content));
    }

    private function assertCreateFolderOutcome(bool $collides, string $victim, string $newPath, array $before, ApiResponse $response, array $log): void
    {
        if ($collides) {
            $this->assertResult(false, 'exists', $response);
            $this->assertSame([[FileEvent::EVENT_CREATE_FOLDER_ERROR, null, 'exists']], $log);
            $this->assertNotOnDisk($newPath);
            $this->assertSame($before, $this->hashmapRows());
        } else {
            $this->assertResult(true, '', $response);
            $this->assertSame([FileEvent::EVENT_CREATE_FOLDER_SUCCESS], array_column($log, 0));
            $this->assertDirOnDisk($newPath);
            $this->assertRowsKept($before, [$newPath]);
        }
        $this->assertDirOnDisk($victim);
    }

    /**
     * As the acting user: the item is not listed in $listDir (which lists exactly $expectedListing),
     * its permissions can be neither read nor changed, and it cannot be removed
     *
     * @param string[] $expectedListing
     */
    private function assertAttackerLockedOut(string $victim, string $listDir, array $expectedListing): void
    {
        sort($expectedListing);
        $this->assertSame($expectedListing, $this->listedNames($this->apiPost('list', ['path' => $listDir])));

        $resolve = $this->apiPost('resolvePermissions', ['path' => $victim]);
        $this->assertStatus(200, $resolve);
        $this->assertSame(['auth' => false], $resolve->json());

        $change = $this->apiPost('changePermissions', [
            'path' => $victim,
            'item' => ['authRead' => [['role' => 'RoleA']], 'authUpdate' => [['role' => 'RoleA']], 'authDelete' => [['role' => 'RoleA']]],
        ]);
        $this->assertResult(false, 'You are not allowed to update this record', $change);

        $remove = $this->apiPost('remove', ['items' => [$victim]]);
        $this->assertResult(false, 'You are not allowed to delete some records', $remove);
        $this->assertTrue(file_exists($this->diskPath($victim)), "$victim must still exist on disk");
    }

    /**
     * Every row of $before is still there byte for byte, and the only further rows are $addedPaths
     *
     * @param array[] $before
     * @param string[] $addedPaths
     */
    private function assertRowsKept(array $before, array $addedPaths): void
    {
        $after = $this->hashmapRows();
        $beforePaths = array_column($before, 'path');
        $kept = array_values(array_filter($after, static function (array $row) use ($beforePaths) {
            return in_array($row['path'], $beforePaths, true);
        }));
        $this->assertSame($before, $kept, 'rows that existed before');
        $added = array_values(array_diff(array_column($after, 'path'), $beforePaths));
        sort($addedPaths);
        sort($added);
        $this->assertSame($addedPaths, $added, 'rows added');
    }

    /**
     * @param array[] $rows
     */
    private function rowOf(array $rows, string $path): ?array
    {
        foreach ($rows as $row) {
            if ($row['path'] === $path) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Error messages the hashmap services logged so far (a failed save, as opposed to a recognised
     * collision that is not written at all)
     *
     * @return string[]
     */
    private function accessErrorsLogged(): array
    {
        $errors = [];
        foreach (Yii::getLogger()->messages as $message) {
            [$text, $level, $category] = $message;
            if ($level === Logger::LEVEL_ERROR && strpos((string)$category, 'hrzg\\filefly\\components\\access\\') === 0) {
                $errors[] = $category . ': ' . (is_string($text) ? $text : print_r($text, true));
            }
        }
        return $errors;
    }
}
