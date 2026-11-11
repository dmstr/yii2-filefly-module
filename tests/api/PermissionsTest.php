<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;
use Yii;

/**
 * Characterisation of WHO may do WHAT through the filefly API (access control on hashmap rows).
 *
 * Safety net for replacing the Flysystem plugins (GrantAccess, GetPermissions, UpdatePermission, ...)
 * by a service: everything is asserted through HTTP, disk and hashmap rows only.
 *
 * Conventions of this file:
 * - the acting non-admin user has id 5, "someone else" has id 7, the root user has id 1
 * - role `RoleA` is assigned to the acting user, `Secret` exists but is assigned to nobody
 * - access columns that are not under test are set to `Secret`, so a wrong column cannot grant access
 *
 * Self-heal, isEmpty and hashmap side effects of the actions: see SelfHealTest.
 */
class PermissionsTest extends ApiTestCase
{
    // ------------------------------------------------------------------
    // access matrix
    // ------------------------------------------------------------------

    /**
     * [actor, access_owner of the item, value of the access column under test, access granted]
     */
    public function accessCases(): array
    {
        return [
            'owner of the item' => ['user', '5', 'Secret', true],
            'owner value numerically equal to user id' => ['user', '05', 'Secret', true],
            'matching role in access column' => ['user', self::OTHER_ID, 'RoleA', true],
            'star in access column' => ['user', self::OTHER_ID, '*', true],
            'no match' => ['user', self::OTHER_ID, 'Secret', false],
            'root user' => ['root', self::OTHER_ID, 'Secret', true],
            'FileflyAdmin role assigned directly' => ['fileflyAdmin', self::OTHER_ID, 'Secret', true],
            'matching role reached through a parent role' => ['childRole', self::OTHER_ID, 'RoleA', true],
            'FileflyAdmin only reached through a parent role' => ['indirectFileflyAdmin', self::OTHER_ID, 'Secret', false],
            'RBAC role Admin of ActiveRecordAccessTrait' => ['rbacAdmin', self::OTHER_ID, 'Secret', true],
        ];
    }

    /**
     * @dataProvider accessCases
     */
    public function testListShowsItemOnlyWithReadAccess(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', $owner, $value, 'Secret', 'Secret', 1);

        $response = $this->apiPost('list', ['path' => '/']);

        $this->assertStatus(200, $response);
        $this->assertSame($allowed ? ['x.txt'] : [], $this->listedNames($response));
        $this->assertSame(['/x.txt'], $this->rowPaths());
        $this->assertAccess('/x.txt', 'file', $owner, $value, 'Secret', 'Secret');
    }

    /**
     * @dataProvider accessCases
     */
    public function testSearchReturnsItemOnlyWithReadAccess(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', $owner, $value, 'Secret', 'Secret', 1);

        $response = $this->apiGet('search', ['q' => 'x.txt']);

        $this->assertStatus(200, $response);
        $expected = $allowed ? [$this->searchHit('/x.txt')] : [];
        $this->assertSame($expected, $response->json());
        $this->assertAccess('/x.txt', 'file', $owner, $value, 'Secret', 'Secret');
    }

    /**
     * @dataProvider accessCases
     */
    public function testStreamIgnoresReadAccess(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'secret content');
        $this->seedRow('/x.txt', 'file', $owner, $value, 'Secret', 'Secret', 14);

        $response = $this->apiStream('/x.txt');

        // NOTE: current behaviour, looks unintended: stream never checks access_read, every user with the
        // route permission gets every file, also those hidden from list/search ($allowed is irrelevant)
        $this->assertStatus(200, $response);
        $this->assertSame('secret content', $response->body);
        $this->assertSame(['/x.txt'], $this->rowPaths());
    }

    /**
     * @dataProvider accessCases
     */
    public function testDownloadIgnoresReadAccess(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'secret content');
        $this->seedRow('/x.txt', 'file', $owner, $value, 'Secret', 'Secret', 14);

        $response = $this->apiDownload('/x.txt');

        // NOTE: current behaviour, looks unintended: download never checks access_read either
        $this->assertStatus(200, $response);
        $this->assertSame('secret content', $response->body);
        $this->assertSame(['/x.txt'], $this->rowPaths());
    }

    /**
     * @dataProvider accessCases
     */
    public function testCreateFolderRequiresUpdateAccessOnParent(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', $owner, 'Secret', $value, 'Secret');

        $response = $this->apiPost('createFolder', ['newPath' => '/d/new']);

        $this->assertStatus(200, $response);
        $this->assertAccess('/d', 'dir', $owner, 'Secret', $value, 'Secret');
        if ($allowed) {
            $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
            $this->assertDirOnDisk('/d/new');
            // new rows get the acting user as owner and the module defaults, nothing is inherited
            $this->assertAccess('/d/new', 'dir', $this->actorId($actor), '*', '*', '*');
            $this->assertSame(['/d', '/d/new'], $this->rowPaths());
        } else {
            $this->assertSame(['success' => false, 'error' => 'nopermission'], $response->json()['result']);
            $this->assertNotOnDisk('/d/new');
            $this->assertSame(['/d'], $this->rowPaths());
        }
    }

    /**
     * @dataProvider accessCases
     */
    public function testUploadRequiresUpdateAccessOnDestination(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', $owner, 'Secret', $value, 'Secret');

        $response = $this->apiUpload('/d', ['u.txt' => 'hi']);

        $this->assertAccess('/d', 'dir', $owner, 'Secret', $value, 'Secret');
        if ($allowed) {
            $this->assertStatus(200, $response);
            $this->assertSame(['success' => true, 'error' => '', 'uploadedFiles' => ['d/u.txt']], $response->json()['result']);
            $this->assertFileOnDisk('/d/u.txt', 'hi');
            $this->assertAccess('/d/u.txt', 'file', $this->actorId($actor), '*', '*', '*');
            $this->assertHashmapRow('/d/u.txt', ['size' => '2']);
            $this->assertSame(['/d', '/d/u.txt'], $this->rowPaths());
        } else {
            $this->assertStatus(400, $response);
            $this->assertSame([
                'success' => false,
                'error' => 'You are not allowed to upload files to this directory',
                'uploadedFiles' => [],
            ], $response->json()['result']);
            $this->assertNotOnDisk('/d/u.txt');
            $this->assertSame(['/d'], $this->rowPaths());
        }
    }

    /**
     * @dataProvider accessCases
     */
    public function testRenameRequiresUpdateAccessOnItem(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/d/x.txt', 'x');
        $this->seedRow('/d', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/d/x.txt', 'file', $owner, 'Secret', $value, 'Secret', 1);

        $response = $this->apiPost('rename', ['item' => '/d/x.txt', 'newItemPath' => '/d/y.txt']);

        $this->assertStatus(200, $response);
        if ($allowed) {
            $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
            $this->assertNotOnDisk('/d/x.txt');
            $this->assertFileOnDisk('/d/y.txt', 'x');
            // the row is moved, owner and access columns are kept
            $this->assertAccess('/d/y.txt', 'file', $owner, 'Secret', $value, 'Secret');
            $this->assertSame(['/d', '/d/y.txt'], $this->rowPaths());
        } else {
            $this->assertSame(['success' => false, 'error' => 'You are not allowed to edit this file'], $response->json()['result']);
            $this->assertFileOnDisk('/d/x.txt', 'x');
            $this->assertNotOnDisk('/d/y.txt');
            $this->assertAccess('/d/x.txt', 'file', $owner, 'Secret', $value, 'Secret');
            $this->assertSame(['/d', '/d/x.txt'], $this->rowPaths());
        }
    }

    /**
     * @dataProvider accessCases
     */
    public function testMoveRequiresUpdateAccessOnDestinationOnly(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/s.txt', 's');
        // source is fully closed for the acting user: move does not look at it
        $this->seedRow('/s.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', $owner, 'Secret', $value, 'Secret');

        $response = $this->apiPost('move', ['items' => ['/s.txt'], 'newPath' => '/d']);

        $this->assertStatus(200, $response);
        $this->assertAccess('/d', 'dir', $owner, 'Secret', $value, 'Secret');
        if ($allowed) {
            $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
            $this->assertNotOnDisk('/s.txt');
            $this->assertFileOnDisk('/d/s.txt', 's');
            $this->assertAccess('/d/s.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
            $this->assertSame(['/d', '/d/s.txt'], $this->rowPaths());
        } else {
            $this->assertSame(['success' => false, 'error' => 'nopermission'], $response->json()['result']);
            $this->assertFileOnDisk('/s.txt', 's');
            $this->assertNotOnDisk('/d/s.txt');
            $this->assertSame(['/d', '/s.txt'], $this->rowPaths());
        }
    }

    /**
     * @dataProvider accessCases
     */
    public function testCopyRequiresUpdateAccessOnDestinationOnly(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/s.txt', 's');
        $this->seedRow('/s.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', $owner, 'Secret', $value, 'Secret');

        $response = $this->apiPost('copy', ['items' => ['/s.txt'], 'newPath' => '/d']);

        $this->assertStatus(200, $response);
        $this->assertFileOnDisk('/s.txt', 's');
        $this->assertAccess('/s.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        if ($allowed) {
            $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
            $this->assertFileOnDisk('/d/s.txt', 's');
            // the copy belongs to the acting user with default permissions
            $this->assertAccess('/d/s.txt', 'file', $this->actorId($actor), '*', '*', '*');
            $this->assertSame(['/d', '/d/s.txt', '/s.txt'], $this->rowPaths());
        } else {
            $this->assertSame(['success' => false, 'error' => 'nopermission'], $response->json()['result']);
            $this->assertNotOnDisk('/d/s.txt');
            $this->assertSame(['/d', '/s.txt'], $this->rowPaths());
        }
    }

    /**
     * @dataProvider accessCases
     */
    public function testRemoveRequiresDeleteAccessOnItem(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', $owner, 'Secret', 'Secret', $value, 1);

        $response = $this->apiPost('remove', ['items' => ['/x.txt']]);

        $this->assertStatus(200, $response);
        if ($allowed) {
            $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
            $this->assertNotOnDisk('/x.txt');
            $this->assertSame([], $this->rowPaths());
        } else {
            $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
            $this->assertFileOnDisk('/x.txt', 'x');
            $this->assertAccess('/x.txt', 'file', $owner, 'Secret', 'Secret', $value);
        }
    }

    /**
     * @dataProvider accessCases
     */
    public function testChangePermissionsIsAllowedForEveryApiUser(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', $owner, $value, $value, $value, 1);

        $response = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => $this->permissionItem(['RoleB'], ['RoleB'], ['RoleB']),
        ]);

        // NOTE: current behaviour, looks unintended: changePermissions checks neither owner nor any access
        // column, every API user can rewrite the permissions of every item ($allowed is irrelevant)
        $this->assertStatus(200, $response);
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertAccess('/x.txt', 'file', $owner, 'RoleB', 'RoleB', 'RoleB');
    }

    /**
     * @dataProvider accessCases
     */
    public function testResolvePermissionsIsAllowedForEveryApiUser(string $actor, string $owner, string $value, bool $allowed): void
    {
        $this->actAsCase($actor);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', $owner, $value, $value, $value, 1);

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        // resolvePermissions has no access check either; the structure is pinned in the tests below
        $this->assertStatus(200, $response);
        $auth = $response->json()['auth'];
        $this->assertSame(['read', 'update', 'delete'], array_keys($auth));
        $this->assertAccess('/x.txt', 'file', $owner, $value, $value, $value);
    }

    // ------------------------------------------------------------------
    // dedicated security pins
    // ------------------------------------------------------------------

    public function testNonOwnerGrantsHimselfReadAccessViaChangePermissions(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/private.txt', 'p');
        $this->seedRow('/private.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);

        $change = $this->apiPost('changePermissions', [
            'path' => '/private.txt',
            'item' => $this->permissionItem(['*'], ['*'], ['*']),
        ]);
        $list = $this->apiPost('list', ['path' => '/']);

        // NOTE: current behaviour, looks unintended: privilege escalation, roles are not validated against
        // the caller's roles and ownership is not checked
        $this->assertSame(['success' => true, 'error' => ''], $change->json()['result']);
        $this->assertSame(['private.txt'], $this->listedNames($list));
        $this->assertAccess('/private.txt', 'file', self::OTHER_ID, '*', '*', '*');
    }

    public function testMoveTakesAnUnreadableFileOfAnotherUserIntoOwnFolder(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/other/secret.txt', 's');
        $this->seedRow('/other', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/other/secret.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedDir('/mine');
        $this->seedRow('/mine', 'dir', '5', '*', '*', '*');

        $response = $this->apiPost('move', ['items' => ['/other/secret.txt'], 'newPath' => '/mine']);

        // NOTE: current behaviour, looks unintended: the source item is neither read-, update- nor
        // delete-checked, only the destination folder
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/other/secret.txt');
        $this->assertFileOnDisk('/mine/secret.txt', 's');
        $this->assertAccess('/mine/secret.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->assertSame(['/mine', '/mine/secret.txt', '/other'], $this->rowPaths());
    }

    public function testCopyMakesAnUnreadableFileOfAnotherUserReadableForTheCopier(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/other/secret.txt', 's');
        $this->seedRow('/other', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/other/secret.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedDir('/mine');
        $this->seedRow('/mine', 'dir', '5', 'Secret', 'Secret', 'Secret');

        $copy = $this->apiPost('copy', ['items' => ['/other/secret.txt'], 'newPath' => '/mine']);
        $list = $this->apiPost('list', ['path' => '/mine']);

        // NOTE: current behaviour, looks unintended: copy does not check read access on the source,
        // the copy is owned by the copier with default permissions ('*')
        $this->assertSame(['success' => true, 'error' => ''], $copy->json()['result']);
        $this->assertFileOnDisk('/mine/secret.txt', 's');
        $this->assertAccess('/mine/secret.txt', 'file', '5', '*', '*', '*');
        $this->assertSame(['secret.txt'], $this->listedNames($list));
    }

    public function testRenameDoesNotCheckTheDestinationFolder(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/mine/x.txt', 'x');
        $this->seedRow('/mine', 'dir', '5', '*', '*', '*');
        $this->seedRow('/mine/x.txt', 'file', '5', '*', '*', '*', 1);
        $this->seedDir('/closed');
        $this->seedRow('/closed', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');

        $response = $this->apiPost('rename', ['item' => '/mine/x.txt', 'newItemPath' => '/closed/x.txt']);

        // NOTE: current behaviour, looks unintended: rename works as move into a folder without update access
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/closed/x.txt', 'x');
        $this->assertAccess('/closed/x.txt', 'file', '5', '*', '*', '*');
        $this->assertSame(['/closed', '/closed/x.txt', '/mine'], $this->rowPaths());
    }

    // ------------------------------------------------------------------
    // inheritance
    // ------------------------------------------------------------------

    public function testEmptyReadColumnInheritsFromParentRow(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'RoleA', 'Secret', 'Secret');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/a']);

        $this->assertSame(['f.txt'], $this->listedNames($list));
    }

    public function testNullReadColumnInheritsFromParentRowInSearch(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'RoleA', 'Secret', 'Secret');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, null, 'Secret', 'Secret', 1);

        $search = $this->apiGet('search', ['q' => 'f.txt']);

        $this->assertSame([$this->searchHit('/a/f.txt')], $search->json());
    }

    public function testEmptyReadColumnDeniedWhenParentDenies(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $search = $this->apiGet('search', ['q' => 'f.txt']);

        $this->assertSame([], $search->json());
    }

    public function testChildReadColumnWinsOverAllowingParent(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedFile('/a/g.txt', 'g');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', 'Secret', 'Secret');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedRow('/a/g.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/a']);

        $this->assertSame(['g.txt'], $this->listedNames($list));
    }

    public function testChildReadColumnWinsOverDenyingParentInSearch(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, 'RoleA', 'Secret', 'Secret', 1);

        $search = $this->apiGet('search', ['q' => 'f.txt']);
        $this->assertSame([$this->searchHit('/a/f.txt')], $search->json());
    }

    public function testListOfDenyingParentIsEmptyEvenIfChildAllows(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, 'RoleA', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/a']);

        $this->assertStatus(200, $list);
        $this->assertSame([], $list->json()['result']);
    }

    public function testReadInheritanceOverThreeLevels(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/l1/l2/l3/f.txt', 'f');
        $this->seedRow('/l1', 'dir', self::OTHER_ID, 'RoleA', 'Secret', 'Secret');
        $this->seedRow('/l1/l2', 'dir', self::OTHER_ID, '', 'Secret', 'Secret');
        $this->seedRow('/l1/l2/l3', 'dir', self::OTHER_ID, '', 'Secret', 'Secret');
        $this->seedRow('/l1/l2/l3/f.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/l1/l2/l3']);

        $this->assertSame(['f.txt'], $this->listedNames($list));
    }

    public function testReadInheritanceOverThreeLevelsDeniedByTopLevel(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/l1/l2/l3/f.txt', 'f');
        $this->seedRow('/l1', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/l1/l2', 'dir', self::OTHER_ID, '', 'Secret', 'Secret');
        $this->seedRow('/l1/l2/l3', 'dir', self::OTHER_ID, '', 'Secret', 'Secret');
        $this->seedRow('/l1/l2/l3/f.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $search = $this->apiGet('search', ['q' => 'f.txt']);

        $this->assertSame([], $search->json());
    }

    public function testOwnerOfIntermediateDirWithEmptyColumnGrantsReadToDescendants(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/b/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/a/b', 'dir', '5', '', 'Secret', 'Secret');
        $this->seedRow('/a/b/f.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $search = $this->apiGet('search', ['q' => 'f.txt']);

        $this->assertSame([$this->searchHit('/a/b/f.txt')], $search->json());
    }

    public function testIntermediateDirWithoutRowBlocksReadInheritance(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/b/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, '*', 'Secret', 'Secret');
        $this->seedRow('/a/b/f.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $search = $this->apiGet('search', ['q' => 'f.txt']);

        // a missing row on the way up denies read (and delete), see the update counterpart below
        $this->assertSame([], $search->json());
        $this->assertSame(['/a', '/a/b/f.txt'], $this->rowPaths());
    }

    public function testIntermediateDirWithoutRowIsSkippedForUpdateInheritance(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedDir('/a/b');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'RoleA', 'Secret');

        $response = $this->apiUpload('/a/b', ['u.txt' => 'u']);

        // NOTE: current behaviour, looks unintended: update skips missing rows and inherits from further up,
        // read and delete stop at a missing row
        $this->assertApiSuccess($response);
        $this->assertFileOnDisk('/a/b/u.txt', 'u');
        $this->assertSame(['/a', '/a/b/u.txt'], $this->rowPaths());
        $this->assertAccess('/a/b/u.txt', 'file', '5', '*', '*', '*');
    }

    public function testIntermediateDirWithoutRowBlocksDeleteInheritance(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedFile('/a/b/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'RoleA');
        $this->seedRow('/a/b/f.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', '', 1);

        $response = $this->apiPost('remove', ['items' => ['/a/b/f.txt']]);

        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertFileOnDisk('/a/b/f.txt', 'f');
        $this->assertSame(['/a', '/a/b/f.txt'], $this->rowPaths());
    }

    public function testUpdateInheritanceOverThreeLevels(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/l1/l2/l3');
        $this->seedRow('/l1', 'dir', self::OTHER_ID, 'Secret', 'RoleA', 'Secret');
        $this->seedRow('/l1/l2', 'dir', self::OTHER_ID, 'Secret', '', 'Secret');
        $this->seedRow('/l1/l2/l3', 'dir', self::OTHER_ID, 'Secret', '', 'Secret');

        $response = $this->apiPost('createFolder', ['newPath' => '/l1/l2/l3/new']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertDirOnDisk('/l1/l2/l3/new');
        $this->assertAccess('/l1/l2/l3/new', 'dir', '5', '*', '*', '*');
    }

    public function testUpdateInheritanceOverThreeLevelsDeniedByTopLevel(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/l1/l2/l3');
        $this->seedRow('/l1', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/l1/l2', 'dir', self::OTHER_ID, 'Secret', '', 'Secret');
        $this->seedRow('/l1/l2/l3', 'dir', self::OTHER_ID, 'Secret', '', 'Secret');

        $response = $this->apiPost('createFolder', ['newPath' => '/l1/l2/l3/new']);

        $this->assertSame(['success' => false, 'error' => 'nopermission'], $response->json()['result']);
        $this->assertNotOnDisk('/l1/l2/l3/new');
    }

    public function testChildUpdateColumnWinsOverDenyingParent(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/a/x.txt', 'file', self::OTHER_ID, 'Secret', '*', 'Secret', 1);

        $response = $this->apiPost('rename', ['item' => '/a/x.txt', 'newItemPath' => '/a/y.txt']);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertFileOnDisk('/a/y.txt', 'x');
    }

    public function testChildUpdateColumnWinsOverAllowingParent(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/x.txt', 'x');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', '*', 'Secret');
        $this->seedRow('/a/x.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);

        $response = $this->apiPost('rename', ['item' => '/a/x.txt', 'newItemPath' => '/a/y.txt']);

        $this->assertSame(['success' => false, 'error' => 'You are not allowed to edit this file'], $response->json()['result']);
        $this->assertFileOnDisk('/a/x.txt', 'x');
    }

    public function testDeleteInheritanceOverThreeLevels(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/l1/l2/l3/f.txt', 'f');
        $this->seedRow('/l1', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'RoleA');
        $this->seedRow('/l1/l2', 'dir', self::OTHER_ID, 'Secret', 'Secret', '');
        $this->seedRow('/l1/l2/l3', 'dir', self::OTHER_ID, 'Secret', 'Secret', '');
        $this->seedRow('/l1/l2/l3/f.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', '', 1);

        $response = $this->apiPost('remove', ['items' => ['/l1/l2/l3/f.txt']]);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/l1/l2/l3/f.txt');
        $this->assertSame(['/l1', '/l1/l2', '/l1/l2/l3'], $this->rowPaths());
    }

    public function testChildDeleteColumnWinsOverAllowingParent(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/a/f.txt', 'f');
        $this->seedRow('/a', 'dir', self::OTHER_ID, 'Secret', 'Secret', '*');
        $this->seedRow('/a/f.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);

        $response = $this->apiPost('remove', ['items' => ['/a/f.txt']]);

        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertFileOnDisk('/a/f.txt', 'f');
    }

    public function testItemInRootWithEmptyReadColumnIsHiddenBecauseRootHasNoRow(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame([], $this->listedNames($list));
    }

    public function testItemInRootWithEmptyUpdateColumnCannotBeRenamed(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, 'Secret', '', 'Secret', 1);

        $response = $this->apiPost('rename', ['item' => '/x.txt', 'newItemPath' => '/y.txt']);

        $this->assertSame(['success' => false, 'error' => 'You are not allowed to edit this file'], $response->json()['result']);
        $this->assertFileOnDisk('/x.txt', 'x');
    }

    public function testSeededRootRowIsUsedForInheritance(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/', 'dir', self::OTHER_ID, 'RoleA', 'Secret', 'Secret');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame(['x.txt'], $this->listedNames($list));
    }

    public function testCreateFolderDirectlyBelowRootIsDeniedForNonAdmin(): void
    {
        $this->actAsApiUser();

        $response = $this->apiPost('createFolder', ['newPath' => '/new']);

        $this->assertSame(['success' => false, 'error' => 'nopermission'], $response->json()['result']);
        $this->assertNotOnDisk('/new');
        $this->assertSame([], $this->rowPaths());
    }

    public function testUploadMoveAndCopyIntoRootAreAllowedForEveryApiUser(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/d/m.txt', 'm');
        $this->seedFile('/d/c.txt', 'c');
        $this->seedRow('/d', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedRow('/d/m.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);
        $this->seedRow('/d/c.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret', 1);

        $upload = $this->apiUpload('/', ['u.txt' => 'u']);
        $move = $this->apiPost('move', ['items' => ['/d/m.txt'], 'newPath' => '/']);
        $copy = $this->apiPost('copy', ['items' => ['/d/c.txt'], 'newPath' => '/']);

        // `/` itself is always manageable while rootFolderManageRole is null
        $this->assertApiSuccess($upload);
        $this->assertSame(['success' => true, 'error' => ''], $move->json()['result']);
        $this->assertSame(['success' => true, 'error' => ''], $copy->json()['result']);
        $this->assertFileOnDisk('/u.txt', 'u');
        $this->assertFileOnDisk('/m.txt', 'm');
        $this->assertFileOnDisk('/c.txt', 'c');
        $this->assertAccess('/u.txt', 'file', '5', '*', '*', '*');
        $this->assertAccess('/m.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->assertAccess('/c.txt', 'file', '5', '*', '*', '*');
        $this->assertSame(['/c.txt', '/d', '/d/c.txt', '/m.txt', '/u.txt'], $this->rowPaths());
    }

    /**
     * Mixed children: pins exactly which items a non-admin sees, with repair on (default)
     */
    public function testListOfDirectoryWithMixedChildAccess(): void
    {
        $this->actAsApiUser();
        $this->seedMixedDirectory();

        $list = $this->apiPost('list', ['path' => '/m']);

        $this->assertStatus(200, $list);
        $this->assertSame(
            ['inherit.txt', 'mine-empty.txt', 'mine.txt', 'norow.txt', 'open-sub', 'pub.txt', 'role.txt'],
            $this->listedNames($list)
        );
        // norow.txt was repaired with the default permissions
        $this->assertAccess('/m/norow.txt', 'file', '1', '*', '*', '*');
    }

    /**
     * Same directory with repair off: the item check is a direct check without inheritance
     */
    public function testListOfDirectoryWithMixedChildAccessWithoutRepair(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedMixedDirectory();

        $list = $this->apiPost('list', ['path' => '/m']);

        // NOTE: current behaviour, looks unintended: with repair off list does not inherit read access
        // (inherit.txt, open-sub hidden) and an empty column hides an item even from its owner (mine-empty.txt)
        $this->assertSame(['mine.txt', 'pub.txt', 'role.txt'], $this->listedNames($list));
        $this->assertNoHashmapRow('/m/norow.txt');
    }

    public function testAdminSeesEveryChildOfMixedDirectory(): void
    {
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedMixedDirectory();

        $list = $this->apiPost('list', ['path' => '/m']);

        $this->assertSame(
            ['csv.txt', 'inherit.txt', 'mine-empty.txt', 'mine.txt', 'norow.txt', 'open-sub', 'pub.txt', 'role.txt', 'secret-sub', 'secret.txt'],
            $this->listedNames($list)
        );
    }

    // ------------------------------------------------------------------
    // comma separated access values (suspected bug, pinned)
    // ------------------------------------------------------------------

    public function testCommaSeparatedReadValueHidesItemFromRoleHolder(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, 'RoleA,RoleB', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/']);
        $search = $this->apiGet('search', ['q' => 'x.txt']);

        // NOTE: current behaviour, looks unintended: the whole column is passed to can('RoleA,RoleB')
        $this->assertSame([], $this->listedNames($list));
        $this->assertSame([], $search->json());
    }

    public function testCommaSeparatedReadValueWithStarHidesItem(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '*,RoleA', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/']);

        // NOTE: current behaviour, looks unintended: only the exact value '*' means everyone
        $this->assertSame([], $this->listedNames($list));
    }

    public function testCommaSeparatedReadValueStillVisibleForOwner(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', 'RoleA,RoleB', 'Secret', 'Secret', 1);

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertSame(['x.txt'], $this->listedNames($list));
    }

    public function testCommaSeparatedUpdateValueDeniesUploadAndCreateFolder(): void
    {
        $this->actAsApiUser();
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', self::OTHER_ID, 'Secret', 'RoleA,RoleB', 'Secret');

        $upload = $this->apiUpload('/d', ['u.txt' => 'u']);
        $create = $this->apiPost('createFolder', ['newPath' => '/d/new']);

        // NOTE: current behaviour, looks unintended: same can('RoleA,RoleB') problem for update
        $this->assertStatus(400, $upload);
        $this->assertSame('You are not allowed to upload files to this directory', $upload->json()['result']['error']);
        $this->assertSame(['success' => false, 'error' => 'nopermission'], $create->json()['result']);
        $this->assertNotOnDisk('/d/u.txt');
        $this->assertNotOnDisk('/d/new');
        $this->assertSame(['/d'], $this->rowPaths());
    }

    public function testCommaSeparatedDeleteValueDeniesRemove(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'RoleA,RoleB', 1);

        $response = $this->apiPost('remove', ['items' => ['/x.txt']]);

        // NOTE: current behaviour, looks unintended: same can('RoleA,RoleB') problem for delete
        $this->assertSame(['success' => false, 'error' => 'You are not allowed to delete some records'], $response->json()['result']);
        $this->assertFileOnDisk('/x.txt', 'x');
        $this->assertAccess('/x.txt', 'file', self::OTHER_ID, 'Secret', 'Secret', 'RoleA,RoleB');
    }

    public function testChangePermissionsWritesCommaSeparatedValuesThatLockOutTheRoleHolders(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $change = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => $this->permissionItem(['RoleA', 'RoleB'], ['RoleB', 'RoleA'], ['RoleA', '*']),
        ]);
        $list = $this->apiPost('list', ['path' => '/']);

        // NOTE: current behaviour, looks unintended: the widget's multi select produces values the access
        // check cannot match, the user with RoleA loses access to the item he just shared with RoleA
        $this->assertSame(['success' => true, 'error' => ''], $change->json()['result']);
        $this->assertAccess('/x.txt', 'file', self::OTHER_ID, 'RoleA,RoleB', 'RoleB,RoleA', 'RoleA,*');
        $this->assertSame([], $this->listedNames($list));
    }

    // ------------------------------------------------------------------
    // resolvePermissions
    // ------------------------------------------------------------------

    public function testResolvePermissionsForNonOwnerListsOwnRolesWithSelection(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, 'RoleA', '*', 'Secret', 1);

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['auth' => [
            'read' => $this->selection(['*' => false, 'FileflyDefault' => false, 'RoleA' => true]),
            'update' => $this->selection(['*' => true, 'FileflyDefault' => false, 'RoleA' => false]),
            'delete' => $this->selection(['*' => false, 'FileflyDefault' => false, 'RoleA' => false]),
        ]], $response->json());
        $this->assertAccess('/x.txt', 'file', self::OTHER_ID, 'RoleA', '*', 'Secret');
    }

    public function testResolvePermissionsForOwnerIsTheSameAsForNonOwner(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', 'RoleA', '*', 'Secret', 1);

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        $this->assertSame(['auth' => [
            'read' => $this->selection(['*' => false, 'FileflyDefault' => false, 'RoleA' => true]),
            'update' => $this->selection(['*' => true, 'FileflyDefault' => false, 'RoleA' => false]),
            'delete' => $this->selection(['*' => false, 'FileflyDefault' => false, 'RoleA' => false]),
        ]], $response->json());
    }

    public function testResolvePermissionsForRootListsAllRoles(): void
    {
        $this->actingAsAdmin();
        $this->createRoles();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, 'RoleA,RoleB', 'Secret', '*', 1);

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        $all = ['*', 'FileflyAdmin', 'FileflyApi', 'FileflyDefault', 'FileflyPermissions', 'RoleA', 'RoleB', 'Secret'];
        $selected = function (array $roles) use ($all): array {
            $map = [];
            foreach ($all as $role) {
                $map[$role] = in_array($role, $roles, true);
            }
            return $this->selection($map);
        };
        $this->assertSame(['auth' => [
            'read' => $selected(['RoleA', 'RoleB']),
            'update' => $selected(['Secret']),
            'delete' => $selected(['*']),
        ]], $response->json());
    }

    public function testResolvePermissionsForFileflyAdminListsOnlyDirectlyAssignedRoles(): void
    {
        $this->createRoles();
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, 'FileflyAdmin', 'Secret', '*', 1);

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        $this->assertSame(['auth' => [
            'read' => $this->selection(['*' => false, 'FileflyAdmin' => true, 'FileflyDefault' => false]),
            'update' => $this->selection(['*' => false, 'FileflyAdmin' => false, 'FileflyDefault' => false]),
            'delete' => $this->selection(['*' => true, 'FileflyAdmin' => false, 'FileflyDefault' => false]),
        ]], $response->json());
    }

    public function testResolvePermissionsWithEmptyColumnsSelectsNothing(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '', '', '', 1);

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        $none = $this->selection(['*' => false, 'FileflyDefault' => false, 'RoleA' => false]);
        $this->assertSame(['auth' => ['read' => $none, 'update' => $none, 'delete' => $none]], $response->json());
    }

    public function testResolvePermissionsWithNullColumnFails(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, null, '*', '*', 1);

        $response = $this->apiPostWithYiiErrorHandler('resolvePermissions', ['path' => '/x.txt']);

        // NOTE: current behaviour, looks unintended: explode() on a NULL column raises a PHP deprecation,
        // which the Yii error handler turns into a server error
        $this->assertStatus(500, $response);
        $this->assertAccess('/x.txt', 'file', self::OTHER_ID, null, '*', '*');
    }

    public function testResolvePermissionsWithoutRowReturnsFalseAndDoesNotRepair(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('resolvePermissions', ['path' => '/x.txt']);

        $this->assertStatus(200, $response);
        $this->assertSame(['auth' => false], $response->json());
        $this->assertSame([], $this->rowPaths());
    }

    public function testResolvePermissionsMatchesPathExactlyWithoutNormalizing(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $response = $this->apiPost('resolvePermissions', ['path' => 'x.txt']);

        $this->assertSame(['auth' => false], $response->json());
    }

    // ------------------------------------------------------------------
    // changePermissions
    // ------------------------------------------------------------------

    public function testChangePermissionsSetsEachColumnAndKeepsOwner(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', '*', '*', '*', 1);

        $response = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => $this->permissionItem(['RoleA'], ['RoleB'], ['Secret']),
        ]);

        $this->assertStatus(200, $response);
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertAccess('/x.txt', 'file', '5', 'RoleA', 'RoleB', 'Secret');
        $this->assertHashmapRow('/x.txt', ['size' => '1']);
    }

    public function testChangePermissionsWithEmptyListsClearsColumnsToEmptyString(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', 'RoleA', 'RoleA', 'RoleA', 1);

        $response = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => $this->permissionItem([], [], []),
        ]);

        // cleared columns are '' (not NULL), i.e. "inherit from parent"
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertAccess('/x.txt', 'file', '5', '', '', '');
    }

    public function testChangePermissionsIgnoresSelectedFlagAndDeduplicatesRoles(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', '*', '*', '*', 1);

        $response = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => [
                'authRead' => [['role' => 'RoleA', 'selected' => false], ['role' => 'RoleA', 'selected' => true]],
                'authUpdate' => [['role' => 'Unknown role', 'selected' => false]],
                'authDelete' => [['role' => '*']],
                'ignored' => 'x',
            ],
        ]);

        // every listed role is written, `selected` is not evaluated, unknown roles are accepted verbatim
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertAccess('/x.txt', 'file', '5', 'RoleA', 'Unknown role', '*');
    }

    public function testChangePermissionsDoesNotTouchChildRows(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/d/x.txt', 'x');
        $this->seedRow('/d', 'dir', '5', '*', '*', '*');
        $this->seedRow('/d/x.txt', 'file', '5', '', '', '', 1);

        $response = $this->apiPost('changePermissions', [
            'path' => '/d',
            'item' => $this->permissionItem(['RoleA'], ['RoleA'], ['RoleA']),
        ]);

        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertAccess('/d', 'dir', '5', 'RoleA', 'RoleA', 'RoleA');
        $this->assertAccess('/d/x.txt', 'file', '5', '', '', '');
    }

    public function testChangePermissionsOnFileWithoutRowRepairsItWithDefaultOwner(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => $this->permissionItem(['RoleA'], [], []),
        ]);

        // the repaired row belongs to the default owner '1', not to the caller
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertAccess('/x.txt', 'file', '1', 'RoleA', '', '');
    }

    public function testChangePermissionsOnFileWithoutRowFailsWithoutRepair(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('changePermissions', [
            'path' => '/x.txt',
            'item' => $this->permissionItem(['RoleA'], [], []),
        ]);

        $this->assertStatus(200, $response);
        $this->assertSame(['success' => false, 'error' => 'error updating permissions'], $response->json()['result']);
        $this->assertSame([], $this->rowPaths());
    }

    public function testChangePermissionsOnMissingItemCreatesRowAndSucceeds(): void
    {
        $this->actAsApiUser();

        $response = $this->apiPost('changePermissions', [
            'path' => '/missing.txt',
            'item' => $this->permissionItem(['RoleA'], [], []),
        ]);

        // NOTE: current behaviour, looks unintended: self-heal creates a row (type NULL) for a path that does
        // not exist on disk, and the permission change on it reports success
        $this->assertSame(['success' => true, 'error' => ''], $response->json()['result']);
        $this->assertNotOnDisk('/missing.txt');
        $this->assertAccess('/missing.txt', null, '1', 'RoleA', '', '');
    }

    public function testChangePermissionsOnMissingItemFailsWithoutRepair(): void
    {
        $this->configureModule(['repair' => false]);
        $this->actAsApiUser();

        $response = $this->apiPost('changePermissions', [
            'path' => '/missing.txt',
            'item' => $this->permissionItem(['RoleA'], [], []),
        ]);

        $this->assertSame(['success' => false, 'error' => 'error updating permissions'], $response->json()['result']);
        $this->assertSame([], $this->rowPaths());
    }

    public function testChangePermissionsMatchesPathExactlyButRepairsTheNormalizedPath(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');

        $response = $this->apiPost('changePermissions', [
            'path' => 'x.txt',
            'item' => $this->permissionItem(['RoleA'], [], []),
        ]);

        // NOTE: current behaviour, looks unintended: self-heal normalizes to '/x.txt', the update looks up
        // 'x.txt' verbatim and finds nothing
        $this->assertSame(['success' => false, 'error' => 'error updating permissions'], $response->json()['result']);
        $this->assertAccess('/x.txt', 'file', '1', '*', '*', '*');
        $this->assertSame(['/x.txt'], $this->rowPaths());
    }

    public function invalidPermissionItems(): array
    {
        return [
            'missing authUpdate' => [['authRead' => [['role' => 'RoleA']], 'authDelete' => []]],
            'authRead is a string' => [['authRead' => 'RoleA', 'authUpdate' => [], 'authDelete' => []]],
            'entry without role' => [['authRead' => [['name' => 'RoleA']], 'authUpdate' => [], 'authDelete' => []]],
            'empty item' => [[]],
        ];
    }

    /**
     * @dataProvider invalidPermissionItems
     */
    public function testChangePermissionsWithInvalidItemFailsWithServerErrorAndKeepsRow(array $item): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', '*', '*', '*', 1);

        $response = $this->apiPost('changePermissions', ['path' => '/x.txt', 'item' => $item]);

        // PHP warnings on the malformed input become a server error, nothing is written
        $this->assertStatus(500, $response);
        $this->assertAccess('/x.txt', 'file', '5', '*', '*', '*');
    }

    public function testChangePermissionsWithoutItemFailsWithServerError(): void
    {
        $this->actAsApiUser();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', '*', '*', '*', 1);

        // json_decode(null) raises a deprecation, a server error with Yii's error handler
        $response = $this->apiPostWithYiiErrorHandler('changePermissions', ['path' => '/x.txt']);

        $this->assertStatus(500, $response);
        $this->assertAccess('/x.txt', 'file', '5', '*', '*', '*');
    }

    public function testChangePermissionsWithoutPathFailsWithServerError(): void
    {
        $this->actAsApiUser();

        // self-heal trims a NULL path, a deprecation and thus a server error with Yii's error handler
        $response = $this->apiPostWithYiiErrorHandler('changePermissions', ['item' => $this->permissionItem(['RoleA'], [], [])]);

        $this->assertStatus(500, $response);
        $this->assertSame([], $this->rowPaths());
    }

    // ------------------------------------------------------------------
    // rootFolderManageRole
    // ------------------------------------------------------------------

    public function testRootFolderManageRoleDeniesUploadToRootWithoutRole(): void
    {
        $this->createRole('RootManager');
        $this->configureModule(['rootFolderManageRole' => 'RootManager']);
        $this->actAsApiUser();

        $response = $this->apiUpload('/', ['u.txt' => 'u']);

        $this->assertStatus(400, $response);
        $this->assertSame('You are not allowed to upload files to this directory', $response->json()['result']['error']);
        $this->assertNotOnDisk('/u.txt');
        $this->assertSame([], $this->rowPaths());
    }

    public function testRootFolderManageRoleAllowsUploadToRootWithRole(): void
    {
        $this->createRole('RootManager');
        $this->configureModule(['rootFolderManageRole' => 'RootManager']);
        $this->actAsApiUser(['RootManager']);

        $response = $this->apiUpload('/', ['u.txt' => 'u']);

        $this->assertApiSuccess($response);
        $this->assertAccess('/u.txt', 'file', '5', '*', '*', '*');
    }

    public function testRootFolderManageRoleIsCheckedBeforeFileflyAdminBypass(): void
    {
        $this->createRole('RootManager');
        $this->configureModule(['rootFolderManageRole' => 'RootManager']);
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedFile('/d/m.txt', 'm');

        $upload = $this->apiUpload('/', ['u.txt' => 'u']);
        $move = $this->apiPost('move', ['items' => ['/d/m.txt'], 'newPath' => '/']);

        // NOTE: current behaviour, looks unintended: a FileflyAdmin without the root role cannot manage `/`
        $this->assertStatus(400, $upload);
        $this->assertSame(['success' => false, 'error' => 'nopermission'], $move->json()['result']);
        $this->assertNotOnDisk('/u.txt');
        $this->assertFileOnDisk('/d/m.txt', 'm');
    }

    public function testRootFolderManageRoleDoesNotRestrictListingRootOrCreatingFoldersBelowIt(): void
    {
        $this->createRole('RootManager');
        $this->configureModule(['rootFolderManageRole' => 'RootManager']);
        $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
        $this->seedFile('/x.txt', 'x');

        $list = $this->apiPost('list', ['path' => '/']);
        $create = $this->apiPost('createFolder', ['newPath' => '/new']);

        // only checks of the path `/` itself are affected; createFolder checks `/new`
        $this->assertSame(['x.txt'], $this->listedNames($list));
        $this->assertSame(['success' => true, 'error' => ''], $create->json()['result']);
        $this->assertDirOnDisk('/new');
    }

    // ------------------------------------------------------------------
    // gatekeeping before any hashmap check
    // ------------------------------------------------------------------

    public function testGuestIsDeniedOnPostWithActionNotAllowed(): void
    {
        $this->actingAsGuest();
        $this->seedFile('/x.txt', 'x');

        $list = $this->apiPost('list', ['path' => '/']);

        $this->assertStatus(403, $list);
        $this->assertSame('Action not allowed', $list->exception->getMessage());
        $this->assertSame([], $this->rowPaths());
    }

    public function testGuestIsDeniedOnGet(): void
    {
        $this->actingAsGuest();
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '*', '*', '*', 1);

        $stream = $this->apiStream('/x.txt');
        $search = $this->apiGet('search', ['q' => 'x']);

        $this->assertStatus(403, $stream);
        $this->assertSame('Login Required', $stream->exception->getMessage());
        $this->assertStatus(403, $search);
    }

    public function testUserWithoutFileflyDefaultIsDeniedOnEveryPostAction(): void
    {
        $this->actingAsUser(self::USER_ID, [], ['filefly_api']);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', '*', '*', '*', 1);
        $this->seedDir('/d');
        $this->seedRow('/d', 'dir', '5', '*', '*', '*');

        $responses = [
            $this->apiPost('list', ['path' => '/']),
            $this->apiPost('createFolder', ['newPath' => '/d/new']),
            $this->apiUpload('/d', ['u.txt' => 'u']),
            $this->apiPost('remove', ['items' => ['/x.txt']]),
            $this->apiPost('changePermissions', ['path' => '/x.txt', 'item' => $this->permissionItem([], [], [])]),
            $this->apiPost('resolvePermissions', ['path' => '/x.txt']),
        ];

        foreach ($responses as $response) {
            $this->assertStatus(403, $response);
            $this->assertSame('Action not allowed', $response->exception->getMessage());
        }
        $this->assertFileOnDisk('/x.txt', 'x');
        $this->assertNotOnDisk('/d/new');
        $this->assertNotOnDisk('/d/u.txt');
        $this->assertSame(['/d', '/x.txt'], $this->rowPaths());
        $this->assertAccess('/x.txt', 'file', '5', '*', '*', '*');
    }

    public function testUserWithoutFileflyDefaultCanStreamDownloadAndSearchViaGet(): void
    {
        $this->actingAsUser(self::USER_ID, [], ['filefly_api']);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', self::OTHER_ID, '*', 'Secret', 'Secret', 1);

        $stream = $this->apiStream('/x.txt');
        $download = $this->apiDownload('/x.txt');
        $search = $this->apiGet('search', ['q' => 'x.txt']);

        // NOTE: current behaviour, looks unintended: the FileflyDefault check only applies to non-GET requests
        $this->assertStatus(200, $stream);
        $this->assertSame('x', $stream->body);
        $this->assertStatus(200, $download);
        $this->assertSame('x', $download->body);
        $this->assertStatus(200, $search);
        $this->assertSame([$this->searchHit('/x.txt')], $search->json());
    }

    public function testUserWithFileflyDefaultButWithoutApiPermissionIsForbidden(): void
    {
        $this->actingAsUser(self::USER_ID, ['FileflyDefault']);
        $this->seedFile('/x.txt', 'x');
        $this->seedRow('/x.txt', 'file', '5', '*', '*', '*', 1);

        $responses = [
            $this->apiPost('list', ['path' => '/']),
            $this->apiPost('remove', ['items' => ['/x.txt']]),
            $this->apiPost('changePermissions', ['path' => '/x.txt', 'item' => $this->permissionItem([], [], [])]),
            $this->apiStream('/x.txt'),
        ];

        foreach ($responses as $response) {
            $this->assertStatus(403, $response);
            $this->assertInstanceOf(\yii\web\ForbiddenHttpException::class, $response->exception);
        }
        $this->assertFileOnDisk('/x.txt', 'x');
        $this->assertAccess('/x.txt', 'file', '5', '*', '*', '*');
    }

    // ------------------------------------------------------------------
    // private helpers
    // ------------------------------------------------------------------

    /**
     * POST with PHP error handling as in production: tests/bootstrap.php disables Yii's ErrorHandler, which
     * would turn every reported PHP error, deprecations included, into an ErrorException (rendered as 500).
     * Without it a deprecation is only printed and fails the isolated test process.
     */
    private function apiPostWithYiiErrorHandler(string $action, array $params): ApiResponse
    {
        set_error_handler(static function ($code, $message, $file, $line) {
            if (!(error_reporting() & $code)) {
                return false;
            }
            throw new \yii\base\ErrorException($message, $code, $code, $file, $line);
        });
        try {
            return $this->apiPost($action, $params);
        } finally {
            restore_error_handler();
        }
    }

    private function actAsCase(string $actor): void
    {
        $this->createRoles();
        switch ($actor) {
            case 'user':
                $this->actAsApiUser();
                return;
            case 'root':
                $this->actingAsAdmin();
                return;
            case 'fileflyAdmin':
                $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileflyAdmin'], ['filefly_api']);
                return;
            case 'childRole':
                $this->createRole('Editors', ['RoleA']);
                $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'Editors'], ['filefly_api']);
                return;
            case 'indirectFileflyAdmin':
                $this->createRole('FileManagers', ['FileflyAdmin']);
                $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'FileManagers'], ['filefly_api']);
                return;
            case 'rbacAdmin':
                $this->createRole('Admin');
                $this->actingAsUser(self::USER_ID, ['FileflyDefault', 'Admin'], ['filefly_api']);
                return;
        }
        throw new \InvalidArgumentException("Unknown actor $actor");
    }

    private function actorId(string $actor): string
    {
        return $actor === 'root' ? (string)self::ADMIN_ID : (string)self::USER_ID;
    }

    private function permissionItem(array $read, array $update, array $delete): array
    {
        $wrap = static function (array $roles): array {
            return array_map(static function ($role) {
                return ['role' => $role];
            }, $roles);
        };
        return ['authRead' => $wrap($read), 'authUpdate' => $wrap($update), 'authDelete' => $wrap($delete)];
    }

    /**
     * @param array $map role => selected, in the expected order
     */
    private function selection(array $map): array
    {
        $result = [];
        foreach ($map as $role => $selected) {
            $result[] = ['role' => (string)$role, 'selected' => $selected];
        }
        return $result;
    }

    /**
     * /m readable for everyone, children with every kind of read access
     */
    private function seedMixedDirectory(): void
    {
        $this->seedDir('/m');
        $this->seedRow('/m', 'dir', self::OTHER_ID, '*', 'Secret', 'Secret');
        $children = [
            'pub.txt' => [self::OTHER_ID, '*'],
            'role.txt' => [self::OTHER_ID, 'RoleA'],
            'secret.txt' => [self::OTHER_ID, 'Secret'],
            'mine.txt' => ['5', 'Secret'],
            'mine-empty.txt' => ['5', null],
            'inherit.txt' => [self::OTHER_ID, ''],
            'csv.txt' => [self::OTHER_ID, 'RoleA,RoleB'],
        ];
        foreach ($children as $name => [$owner, $read]) {
            $this->seedFile('/m/' . $name, 'c');
            $this->seedRow('/m/' . $name, 'file', $owner, $read, 'Secret', 'Secret', 1);
        }
        $this->seedFile('/m/norow.txt', 'c');
        $this->seedDir('/m/secret-sub');
        $this->seedRow('/m/secret-sub', 'dir', self::OTHER_ID, 'Secret', 'Secret', 'Secret');
        $this->seedDir('/m/open-sub');
        $this->seedRow('/m/open-sub', 'dir', self::OTHER_ID, '', 'Secret', 'Secret');
    }
}
