<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;

/**
 * Characterisation of the hashmap bookkeeping of the filefly API:
 * self-heal (`check`, module option `repair`), the rows written/moved/removed by the actions,
 * and the hashmap based emptiness check (`isEmpty`) used by `remove`.
 *
 * Same conventions as PermissionsTest: acting non-admin user id 5 with FileflyDefault, `filefly_api`
 * and RoleA; "someone else" is id 7; the module default owner of repaired rows is '1'.
 */
class SelfHealTest extends ApiTestCase
{
    // ------------------------------------------------------------------
    // self-heal through list
    // ------------------------------------------------------------------

    public function testListRepairsEveryMissingChildRowWithDefaultsAsNonAdmin(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a.txt', 'a');
        $this->seedFile('/b.txt', 'bb');
        $this->seedFile('/c.txt', 'ccc');
        $this->seedDir('/d1');
        $this->seedFile('/d2/inner.txt', 'i');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame(['a.txt', 'b.txt', 'c.txt', 'd1', 'd2'], $this->listedNames($list));
        $this->assertSame(['/a.txt', '/b.txt', '/c.txt', '/d1', '/d2'], $this->rowPaths());
        // repaired rows get the module defaults including the default owner, not the acting user
        $this->assertAccess('/a.txt', 'file', '1', '*', '*', '*');
        $this->assertAccess('/d1', 'dir', '1', '*', '*', '*');
        $this->assertAccess('/d2', 'dir', '1', '*', '*', '*');
        $this->assertHashmapRow('/a.txt', ['size' => '1']);
        $this->assertHashmapRow('/c.txt', ['size' => '3']);
        $this->assertHashmapRow('/d1', ['size' => null]);
    }

    public function testListRepairsMissingRowsTheSameWayAsRoot(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'a');
        $this->seedDir('/d1');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame(['a.txt', 'd1'], $this->listedNames($list));
        $this->assertSame(['/a.txt', '/d1'], $this->rowPaths());
        $this->assertAccess('/a.txt', 'file', '1', '*', '*', '*');
        $this->assertAccess('/d1', 'dir', '1', '*', '*', '*');
    }

    public function testListRepairUsesConfiguredDefaultPermissions(): void
    {
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '9',
            'access_read' => 'RoleA',
            'access_update' => '',
            'access_delete' => 'Secret',
        ]]);
        $this->actAsApiUser();
        $this->seedFile('/a.txt', 'a');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame(['a.txt'], $this->listedNames($list));
        $this->assertAccess('/a.txt', 'file', '9', 'RoleA', '', 'Secret');
    }

    public function testListRepairWithDefaultsTheUserCannotReadHidesTheRepairedItem(): void
    {
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '9',
            'access_read' => 'Secret',
            'access_update' => 'Secret',
            'access_delete' => 'Secret',
        ]]);
        $this->actAsApiUser();
        $this->seedFile('/a.txt', 'a');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame([], $this->listedNames($list));
        $this->assertAccess('/a.txt', 'file', '9', 'Secret', 'Secret', 'Secret');
    }

    public function testListWithoutRepairCreatesNoRowsAndHidesUnindexedItemsFromNonAdmin(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedFile('/a.txt', 'a');
        $this->seedDir('/d1');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame([], $this->listedNames($list));
        $this->assertSame([], $this->rowPaths());
    }

    public function testListWithoutRepairShowsUnindexedItemsToFileflyAdminWithoutCreatingRows(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedFile('/a.txt', 'a');
        $this->seedDir('/d1');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame(['a.txt', 'd1'], $this->listedNames($list));
        $this->assertSame([], $this->rowPaths());
    }

    public function testListDoesNotRepairTheListedDirectoryItself(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/d/a.txt', 'a');

        $list = $this->apiPost('list', ['path' => '/d']);

        // list passes repair=false for the listed path, only its children are repaired
        $this->assertSame(['a.txt'], $this->listedNames($list));
        $this->assertSame(['/d/a.txt'], $this->rowPaths());
        $this->assertAccess('/d/a.txt', 'file', '1', '*', '*', '*');
    }

    public function testNonAdminListOfUnindexedDirectoryIsEmptyAndRepairsNothing(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/d/a.txt', 'a');

        $list = $this->apiPost('list', ['path' => '/d']);

        // the listed directory has no row, read access is denied before any child is looked at
        $this->assertSame([], $this->listedNames($list));
        $this->assertSame([], $this->rowPaths());
    }

    public function testListingParentFirstRepairsTheDirectorySoItsContentBecomesListable(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/d/a.txt', 'a');

        $this->apiPost('list', ['path' => '/']);
        $list = $this->apiPost('list', ['path' => '/d']);

        $this->assertSame(['a.txt'], $this->listedNames($list));
        $this->assertSame(['/d', '/d/a.txt'], $this->rowPaths());
    }

    public function testListKeepsRowWithoutFileAndSearchStillReturnsIt(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a.txt', 'a');
        $this->seedRow('/a.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);
        $this->seedRow('/gone.txt', 'file', self::OTHER_ID, '*', '*', '*', 4);

        $list = $this->apiPost('list', ['path' => '/']);
        $search = $this->apiGet('search', ['q' => 'gone']);

        // NOTE: current behaviour, looks unintended: orphan rows are only cleaned by the console sync,
        // search reports files that do not exist
        $this->assertSame(['a.txt'], $this->listedNames($list));
        $this->assertSame([$this->searchHit('/gone.txt')], $search->json());
        $this->assertSame(['/a.txt', '/gone.txt'], $this->rowPaths());
    }

    public function testListDoesNotCorrectWrongTypeOrSizeInRow(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'dir', self::OTHER_ID, '*', '*', '*', 999);

        $list = $this->apiPost('list', ['path' => '/']);
        $search = $this->apiGet('search', ['q' => 'x.txt']);

        // list reports type and size from disk, the row is left as it is
        $result = $list->json()['result'];
        $this->assertCount(1, $result);
        $this->assertSame('file', $result[0]['type']);
        $this->assertSame(1, $result[0]['size']);
        $this->assertAccess('/x.txt', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->assertHashmapRow('/x.txt', ['size' => '999']);
        // search filters on the row type (only_files), so the file is not found
        $this->assertSame([], $search->json());
    }

    // ------------------------------------------------------------------
    // self-heal through the other actions
    // ------------------------------------------------------------------

    public function testUploadRepairsTheUnindexedDestinationDirectory(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/d');

        $response = $this->apiUpload('/d', ['u.txt' => 'u']);

        $this->assertApiSuccess($response);
        $this->assertSame(['/d', '/d/u.txt'], $this->rowPaths());
        $this->assertAccess('/d', 'dir', '1', '*', '*', '*');
        $this->assertAccess('/d/u.txt', 'file', '5', '*', '*', '*');
    }

    public function testUploadRepairGrantsUpdateEvenIfAncestorDeniesIt(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/a/b');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');

        $response = $this->apiUpload('/a/b', ['u.txt' => 'u']);

        // NOTE: current behaviour, looks unintended: the repaired row of /a/b gets update '*' from the
        // defaults, which overrides the denying ancestor
        $this->assertApiSuccess($response);
        $this->assertAccess('/a/b', 'dir', '1', '*', '*', '*');
        $this->assertFileOnDisk('/a/b/u.txt', 'u');
    }

    public function testUploadWithoutRepairIntoUnindexedDirectoryBelowRootIsDenied(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedDir('/d');

        $response = $this->apiUpload('/d', ['u.txt' => 'u']);

        $this->assertStatus(400, $response);
        $this->assertSame('You are not allowed to upload files to this directory', $response->json()['result']['error']);
        $this->assertSame([], $this->rowPaths());
    }

    public function testCreateFolderDoesNotRepairTheParentDirectory(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/d');

        $response = $this->apiPost('createFolder', ['newPath' => '/d/new']);

        $this->assertSame(['success' => false, 'error' => 'nopermission'], $response->json()['result']);
        $this->assertNotOnDisk('/d/new');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRenameRepairsTheItemBeforeTheAccessCheck(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('rename', ['item' => '/x.txt', 'newItemPath' => '/y.txt']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/y.txt', 'x');
        $this->assertSame(['/y.txt'], $this->rowPaths());
        $this->assertAccess('/y.txt', 'file', '1', '*', '*', '*');
    }

    public function testRenameWithoutRepairOfUnindexedItemIsDeniedForNonAdmin(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('rename', ['item' => '/x.txt', 'newItemPath' => '/y.txt']);

        $this->assertSame(['success' => false, 'error' => 'You are not allowed to edit this file'], $response->json()['result']);
        $this->assertFileOnDisk('/x.txt', 'x');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRenameWithoutRepairOfUnindexedItemAsAdminCreatesRowForTheOldPath(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('rename', ['item' => '/x.txt', 'newItemPath' => '/y.txt']);

        // NOTE: current behaviour, looks unintended: setAccess finds no row for the old path and creates one
        // for it (type NULL, the file is already gone), the new path gets no row
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/y.txt', 'x');
        $this->assertSame(['/x.txt'], $this->rowPaths());
        $this->assertAccess('/x.txt', null, '5', '*', '*', '*');
    }

    public function testMoveRepairsDestinationAndSource(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/s.txt', 's');
        $this->seedDir('/d');

        $response = $this->apiPost('move', ['items' => ['/s.txt'], 'newPath' => '/d']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/d/s.txt', 's');
        $this->assertSame(['/d', '/d/s.txt'], $this->rowPaths());
        $this->assertAccess('/d', 'dir', '1', '*', '*', '*');
        $this->assertAccess('/d/s.txt', 'file', '1', '*', '*', '*');
    }

    public function testMoveIntoMissingDirectoryRepairsAPhantomRowAndCreatesTheDirectory(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/s.txt', 's');

        $response = $this->apiPost('move', ['items' => ['/s.txt'], 'newPath' => '/nope']);

        // NOTE: current behaviour, looks unintended: self-heal creates a row with type NULL for a path that does
        // not exist, which then grants update '*'; the adapter creates the directory on rename
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/nope/s.txt', 's');
        $this->assertSame(['/nope', '/nope/s.txt'], $this->rowPaths());
        $this->assertAccess('/nope', null, '1', '*', '*', '*');
        $this->assertAccess('/nope/s.txt', 'file', '1', '*', '*', '*');
    }

    public function testCopyRepairsDestinationAndSourceAndCopyBelongsToActor(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/s.txt', 's');
        $this->seedDir('/d');

        $response = $this->apiPost('copy', ['items' => ['/s.txt'], 'newPath' => '/d']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertSame(['/d', '/d/s.txt', '/s.txt'], $this->rowPaths());
        $this->assertAccess('/d', 'dir', '1', '*', '*', '*');
        $this->assertAccess('/s.txt', 'file', '1', '*', '*', '*');
        $this->assertAccess('/d/s.txt', 'file', '5', '*', '*', '*');
    }

    public function testRemoveRepairsThenDeletesUnindexedFile(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('remove', ['items' => ['/x.txt']]);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/x.txt');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRemoveWithoutRepairOfUnindexedFileIsDeniedForNonAdmin(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('remove', ['items' => ['/x.txt']]);

        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertFileOnDisk('/x.txt', 'x');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRemoveWithoutRepairOfUnindexedFileAsAdminDeletesButReportsFailure(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('remove', ['items' => ['/x.txt']]);

        // NOTE: current behaviour, looks unintended: the file is deleted, but removing the (missing) row fails
        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertNotOnDisk('/x.txt');
        $this->assertSame([], $this->rowPaths());
    }

    public function testSearchResolvePermissionsAndStreamDoNotRepair(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $search = $this->apiGet('search', ['q' => 'x.txt']);
        $resolve = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);
        $stream = $this->apiStream('/x.txt');

        $this->assertSame([], $search->json());
        $this->assertSame(['auth' => false], $resolve->json());
        $this->assertStatus(200, $stream);
        $this->assertSame([], $this->rowPaths());
    }

    // ------------------------------------------------------------------
    // rows written by create / upload / copy
    // ------------------------------------------------------------------

    public function testCreatedRowsUseActingUserAsOwnerAndDefaultsForAccessNotTheParentRow(): void
    {
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '9',
            'access_read' => 'RoleB',
            'access_update' => 'RoleA',
            'access_delete' => '',
        ]]);
        $this->actAsApiUser();
        $this->seedFile('/s.txt', 's');
        $this->seedRow('/s.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', self::OTHER_ID, 'Secret', 'RoleA', 'Secret');

        $create = $this->apiPost('createFolder', ['newPath' => '/d/new']);
        $upload = $this->apiUpload('/d', ['u.txt' => 'uu']);
        $copy = $this->apiPost('copy', ['items' => ['/s.txt'], 'newPath' => '/d']);

        $this->assertSame(['success' => true, 'error' => ''], $create->json()['result']);
        $this->assertApiSuccess($upload);
        $this->assertSame(['success' => true, 'error' => ''], $copy->json()['result']);
        $this->assertAccess('/d/new', 'dir', '5', 'RoleB', 'RoleA', '');
        $this->assertAccess('/d/u.txt', 'file', '5', 'RoleB', 'RoleA', '');
        $this->assertAccess('/d/s.txt', 'file', '5', 'RoleB', 'RoleA', '');
        $this->assertHashmapRow('/d/u.txt', ['size' => '2']);
        $this->assertHashmapRow('/d/s.txt', ['size' => '1']);
        $this->assertHashmapRow('/d/new', ['size' => null]);
        $this->assertAccess('/d', 'dir', self::OTHER_ID, 'Secret', 'RoleA', 'Secret');
    }

    public function testCreateFolderOnExistingIndexedDirectoryKeepsAllRows(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/d/x.txt', 'x');
        $this->seedRow('/d', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/d/x.txt', 'file', self::OTHER_ID, 'RoleA', '*', '*', 1);
        $this->seedFile('/docs.txt', 'o');
        $this->seedRow('/docs.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('createFolder', ['newPath' => '/d']);

        $this->assertSame(['success' => false, 'error' => 'exists'], $response->json()['result']);
        $this->assertFileOnDisk('/d/x.txt', 'x');
        $this->assertSame(['/d', '/d/x.txt', '/docs.txt'], $this->rowPaths());
        $this->assertAccess('/d', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->assertAccess('/d/x.txt', 'file', self::OTHER_ID, 'RoleA', '*', '*');
        $this->assertAccess('/docs.txt', 'file', self::OTHER_ID, '*', '*', '*');
    }

    // ------------------------------------------------------------------
    // rows after rename and move
    // ------------------------------------------------------------------

    public function testRenameDirectoryMovesAllChildRowsAndKeepsTheirAccess(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedFile('/a/sub/y.txt', 'y');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'RoleA', '*', 'Secret');
        $this->seedRow('/a/x.txt', 'file', '5', '', 'RoleB', '', 1);
        $this->seedRow('/a/sub', 'dir', self::OTHER_ID, 'Secret', '', 'RoleA');
        $this->seedRow('/a/sub/y.txt', 'file', self::OTHER_ID, '*', 'Secret', '*', 1);

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/z']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/z/x.txt', 'x');
        $this->assertFileOnDisk('/z/sub/y.txt', 'y');
        $this->assertSame(['/z', '/z/sub', '/z/sub/y.txt', '/z/x.txt'], $this->rowPaths());
        $this->assertAccess('/z', 'dir', self::OTHER_ID, 'RoleA', '*', 'Secret');
        $this->assertAccess('/z/x.txt', 'file', '5', '', 'RoleB', '');
        $this->assertAccess('/z/sub', 'dir', self::OTHER_ID, 'Secret', '', 'RoleA');
        $this->assertAccess('/z/sub/y.txt', 'file', self::OTHER_ID, '*', 'Secret', '*');
    }

    public function testRenameKeepsRowsOfSiblingsSharingThePathPrefix(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedFile('/ab.txt', 'b');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);
        $this->seedRow('/ab.txt', 'file', self::OTHER_ID, 'RoleA', 'RoleA', 'RoleA', 1);

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/z']);

        // only the row of /a and the rows below "/a/" move, the sibling /ab.txt keeps its row
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/ab.txt', 'b');
        $this->assertSame(['/ab.txt', '/z', '/z/x.txt'], $this->rowPaths());
        $this->assertAccess('/ab.txt', 'file', self::OTHER_ID, 'RoleA', 'RoleA', 'RoleA');
        $this->assertAccess('/z', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->assertAccess('/z/x.txt', 'file', self::OTHER_ID, '*', '*', '*');
    }

    public function testRenameReplacesOnlyTheLeadingOldPathInChildRows(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/sub/a.txt', 'x');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a/sub', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a/sub/a.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('rename', ['item' => '/a', 'newItemPath' => '/z']);

        // the inner "/a.txt" of the child path stays, so the row still matches the file on disk
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/z/sub/a.txt', 'x');
        $this->assertSame(['/z', '/z/sub', '/z/sub/a.txt'], $this->rowPaths());
        $this->assertAccess('/z/sub/a.txt', 'file', self::OTHER_ID, '*', '*', '*');
    }

    public function testRenameDirectoryReplacesLeadingOldPathAlsoWhenItRepeatsDeeper(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/z/sub/a/z/x.txt', 'x');
        $this->seedRow('/z', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/z/sub', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/z/sub/a', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/z/sub/a/z', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/z/sub/a/z/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('rename', ['item' => '/z', 'newItemPath' => '/q']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/q/sub/a/z/x.txt', 'x');
        $this->assertSame(['/q', '/q/sub', '/q/sub/a', '/q/sub/a/z', '/q/sub/a/z/x.txt'], $this->rowPaths());
    }

    public function testMoveDirectoryMovesAllChildRowsAndKeepsTheirAccess(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'RoleA', 'Secret', 'Secret');
        $this->seedRow('/a/x.txt', 'file', '5', '', 'RoleB', 'Secret', 1);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', self::OTHER_ID, 'Secret', '*', 'Secret');

        $response = $this->apiPost('move', ['items' => ['/a'], 'newPath' => '/d']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/d/a/x.txt', 'x');
        $this->assertNotOnDisk('/a');
        $this->assertSame(['/d', '/d/a', '/d/a/x.txt'], $this->rowPaths());
        $this->assertAccess('/d/a', 'dir', self::OTHER_ID, 'RoleA', 'Secret', 'Secret');
        $this->assertAccess('/d/a/x.txt', 'file', '5', '', 'RoleB', 'Secret');
    }

    // ------------------------------------------------------------------
    // rows after remove, isEmpty
    // ------------------------------------------------------------------

    public function testRemoveDirectoryWithChildRowsWithoutDeleteRecursiveStillDeletesEverything(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedFile('/a/sub/y.txt', 'y');
        $this->seedFile('/ab.txt', 'b');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a/x.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedRow('/a/sub', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/a/sub/y.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedRow('/ab.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('remove', ['items' => ['/a']]);

        // NOTE: current behaviour, looks unintended: with deleteRecursive=false the non-empty directory (by rows)
        // only produces an error message; directory, children (without delete access to them) and all child
        // rows are removed anyway and success is true
        $this->assertSame(['success' => true, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertNotOnDisk('/a');
        $this->assertFileOnDisk('/ab.txt', 'b');
        $this->assertSame(['/ab.txt'], $this->rowPaths());
    }

    public function testRemoveDirectoryWithChildRowsWithDeleteRecursiveReportsNoError(): void
    {
        $this->configureModule(['deleteRecursive' => true]);
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a/x.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);

        $response = $this->apiPost('remove', ['items' => ['/a']]);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/a');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRemoveDirectoryEmptyOnDiskButWithChildRowsCountsAsNotEmpty(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/a');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a/stale.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('remove', ['items' => ['/a']]);

        $this->assertSame(['success' => true, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertNotOnDisk('/a');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRemoveDirectoryWithFilesOnDiskButNoChildRowsCountsAsEmpty(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedFile('/a/sub/y.txt', 'y');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', '*', '*');

        $response = $this->apiPost('remove', ['items' => ['/a']]);

        // NOTE: current behaviour, looks unintended: emptiness is judged by hashmap rows only, the files on
        // disk are deleted with deleteRecursive=false and without any message
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/a');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRemoveDirectoryWithLikeWildcardInNameKeepsRowsOfPathsMatchingTheWildcard(): void
    {
        $this->configureModule(['deleteRecursive' => true]);
        $this->actAsApiUser();
        $this->seedFile('/a_/x.txt', 'x');
        $this->seedFile('/ab/y.txt', 'y');
        $this->seedRow('/a_', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/a_/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);
        $this->seedRow('/ab', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/ab/y.txt', 'file', self::OTHER_ID, 'RoleA', 'RoleA', 'RoleA', 1);

        $response = $this->apiPost('remove', ['items' => ['/a_']]);

        // "_" is matched literally, so only the rows below "/a_/" go with the directory
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/a_');
        $this->assertFileOnDisk('/ab/y.txt', 'y');
        $this->assertSame(['/ab', '/ab/y.txt'], $this->rowPaths());
        $this->assertAccess('/ab/y.txt', 'file', self::OTHER_ID, 'RoleA', 'RoleA', 'RoleA');
    }

    public function testRemoveEmptyDirectoryWithLikeWildcardInNameIgnoresRowsOfPathsMatchingTheWildcard(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/a%');
        $this->seedFile('/abc/y.txt', 'y');
        $this->seedRow('/a%', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/abc', 'dir', self::OTHER_ID, '*', '*', '*');
        $this->seedRow('/abc/y.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('remove', ['items' => ['/a%']]);

        // the emptiness check sees no row below "/a%/", so no error is reported (deleteRecursive is off)
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/a%');
        $this->assertFileOnDisk('/abc/y.txt', 'y');
        $this->assertSame(['/abc', '/abc/y.txt'], $this->rowPaths());
    }

    public function testRemoveDirectoryWithoutOwnRowAndWithoutRepairAsAdminCountsAsNotEmpty(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedDir('/a');

        $response = $this->apiPost('remove', ['items' => ['/a']]);

        // isEmpty is false for a missing row; deletion happens anyway, removing the missing row fails
        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertNotOnDisk('/a');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRemoveWithoutLeadingSlashDeletesFileButKeepsRow(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('remove', ['items' => ['x.txt']]);

        // NOTE: current behaviour, looks unintended: access check and self-heal normalize the path,
        // removing the row looks up 'x.txt' verbatim and fails, the orphan row stays
        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertNotOnDisk('/x.txt');
        $this->assertSame(['/x.txt'], $this->rowPaths());
    }

    public function testRemoveOfSeveralItemsContinuesAfterDeniedItemAndReportsDenial(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/closed.txt', 'c');
        $this->seedFile('/open.txt', 'o');
        $this->seedRow('/closed.txt', 'file', self::OTHER_ID, '*', '*', 'Secret', 1);
        $this->seedRow('/open.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('remove', ['items' => ['/closed.txt', '/open.txt']]);

        $this->assertSame(['success' => true, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertFileOnDisk('/closed.txt', 'c');
        $this->assertNotOnDisk('/open.txt');
        $this->assertSame(['/closed.txt'], $this->rowPaths());
    }
}
