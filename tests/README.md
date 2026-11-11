<!-- file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC] -->

# Tests

## Install and run

`yiisoft/yii2` needs bower assets, so `composer.json` declares the asset-packagist repository. `composer.json` requires `league/flysystem` `^3.35.3`, because advisory `PKSA-w9tt-7782-78jx` (CVE-2026-102601) affects all versions `<=3.35.2` and every 1.x release; no advisory is ignored.

```bash
composer install
vendor/bin/phpunit
```

Run only the API characterisation tests, or only the console tests:

```bash
vendor/bin/phpunit tests/api
vendor/bin/phpunit tests/console
```

The suite is to be run on Linux as well as on macOS, because production runs on Linux and some behaviour differs by OS (see "Error handler and PHP diagnostics"). With Docker, from the repository root, as a non-root user so that `chmod`-based tests behave (replace `<image>` with a PHP 8.4 or 8.5 image that has `pdo_sqlite` and `intl`, for example the phd5 app image; the host-installed `vendor/` is pure PHP and works in the container, `--cache-result-file` keeps the cache file out of the repository):

```bash
docker run --rm --entrypoint sh --user "$(id -u):$(id -g)" \
  -e HOME=/tmp/h -e TMPDIR=/tmp -v "$PWD":/work -w /work <image> \
  -c 'vendor/bin/phpunit --cache-result-file=/tmp/phpunit.cache'
```

Tested with PHP 8.5.1 (macOS) and 8.4 (Linux, `dmstr/phd5-app:5.11.3`), Composer 2.11.2, PHPUnit 9.6.38, yiisoft/yii2 2.0.55, league/flysystem 3.36.0, league/flysystem-local 3.35.3, league/mime-type-detection 1.17.0, creocoder/yii2-flysystem 2.0.0, dmstr/yii2-active-record-permissions 1.3.2, dmstr/yii2-web 1.1.3.

The default run excludes `tests/adapters`: the adapter suite there runs the storage contract, the HTTP API and the console command against real S3 and FTP servers in Docker and has its own configuration and command, see `./adapters/README.md`.

Every test in `tests/api/` runs in its own PHP process (see "Process isolation" below); one isolated test takes roughly 0.05 to 0.1 s. Tests in `tests/console/` share one process (see "Console harness").

`tests/bootstrap.php` masks no diagnostics: a deprecation from any code (the module or a dependency) that an isolated process prints to stderr fails the test.

## Harness

`hrzg\filefly\tests\support\ApiTestCase` boots per test a real `yii\web\Application` with:

- module `filefly` (`hrzg\filefly\Module`, `filesystem => 'fsTest'`)
- `db`: sqlite in memory, table `filefly_hashmap` created in its final migrated form (the migrations themselves use `alterColumn()`, which sqlite does not support); MySQL's `NOW()` (used by the model's `TimestampBehavior`) is registered as sqlite function. Optionally a real MariaDB, MySQL or PostgreSQL database with the schema from the real migrations, see "Real databases" below (`TestDatabase`)
- `fsTest`: `creocoder\flysystem\LocalFilesystem` (creocoder/yii2-flysystem 2, Flysystem 3) rooted in a fresh temp directory (`$this->fsRoot`), with the default `linkHandling` (`DISALLOW_LINKS`) and `config => ['directory_visibility' => 'public']`, so that new directories get `0755` as with Flysystem 1 (creocoder's default is `0740`); this is the configuration `UPGRADE.md` recommends to applications, and it lives only in `filesystemComponentConfig()`
- `authManager`: `yii\rbac\PhpManager`, filled by running the module's RBAC migrations (`FileflyAdmin`, `FileflyApi`, `FileflyDefault`, `FileflyPermissions`)
- `user`: `dmstr\web\User` without session, root user `admin` (id 1) like in phd5
- `settings`: in-memory stand-in for pheme/yii2-settings (`TestSettings`)
- `response`: `TestResponse`, the real `send()` pipeline without output

Requests go through `Application::run()` (routing, `ApiController::runAction()`, module and controller filters, error handler). Each call gets a fresh request, response, module instance and filesystem component; db, RBAC, user and settings persist within the test.

### Helper methods

Acting as (call before the first API call of a test):

- `actingAsAdmin()`: root user `admin`, id 1, every `can()` is true
- `actingAsUser($id, array $roles = [], array $permissions = [], ?string $username = null)`: user with directly assigned roles and permissions (missing permissions are created)
- `actingAsGuest()`: no identity (default after `setUp()`)
- `actingAsFileflyUser(int $id)`: plain API user with role `FileflyDefault` and the route permission `filefly_api`, nothing else
- `actAsApiUser(array $extraRoles = [])`: user `USER_ID` (5) with `FileflyDefault`, `filefly_api` and `RoleA` (plus existing extra roles); creates `RoleA`, `RoleB` and `Secret` first
- `createRole(string $name, array $children = []): Role`, `createRoles()` (`RoleA`, `RoleB`, `Secret`, assigned to nobody), `createPermission(string $name): Permission`
- constants `USER_ID` (5, the plain acting user) and `OTHER_ID` (`'7'`, "someone else", owner of rows seeded for other users), next to `ADMIN_ID` (1)

Module and settings:

- `configureModule(array $properties)`: module properties for all following calls (`repair`, `slugNames`, `deleteRecursive`, `defaultPermissions`, `allowedExtensions`, `rootFolderManageRole`, callbacks, ...)
- `setSetting(string $key, $value, string $section = 'filefly')`: e.g. `mime-whitelist`

Requests (all return `ApiResponse`):

- `apiPost(string $action, array $params = [], array $headers = [])`: JSON body `{"action": ..., ...params}`
- `apiGet(string $action, array $params = [], array $headers = [])`: query string
- `apiUpload(string $destination, array $files, array $headers = [])`: multipart upload, `$files` as `[name => content]` or list of `['name', 'content', 'type', 'error']`
- `apiStream(string $path, array $headers = [])`, `apiDownload(string $path, array $headers = [])`
- `request(string $method, array $query, ?string $rawBody, array $post = [], array $files = [], array $headers = [])`: low level

`ApiResponse`: `$statusCode`, `$headers` (lower-case name => values), `$body` (raw, also streamed file content), `$exception` (exception rendered by the error handler or `null`), `json()`, `header(string $name)`, `describe()`.

Disk:

- `seedFile(string $path, string $content = '')`, `seedDir(string $path)`: directly on disk, no hashmap row
- `diskPath(string $path)`, `readFromDisk(string $path)`
- `disk(): array`: the whole test filesystem as `/path => content` (`null` for directories), sorted by path; compare with `assertSame()`
- `assertFileOnDisk(string $path, ?string $expectedContent = null)`, `assertDirOnDisk(string $path)`, `assertNotOnDisk(string $path)`

Hashmap:

- `seedHashmapRow(string $path, string $type, array $access = [], ?int $size = null)`: row without model or access checks, access columns default to the module's `defaultPermissions`
- `seedRow(string $path, string $type, ?string $owner, ?string $read, ?string $update, ?string $delete, ?int $size = null)`: `seedHashmapRow()` with the four access columns as positional arguments (`null` stays NULL)
- `hashmapRow(string $path): ?array`, `hashmapRows(?string $prefix = null): array`: all values as strings (like MySQL), columns always in the order of the sqlite schema; the path match, the prefix filter and the ordering by path are done in PHP byte by byte, so the helpers report the same rows on every database (a case- or accent-insensitive collation does not make `hashmapRow('/D')` return the row of `/d`)
- `rowPaths(): array`: paths of all hashmap rows, ordered by path
- `assertHashmapRow(string $path, array $expected = [])` (subset of columns), `assertNoHashmapRow(string $path)`
- `assertAccess(string $path, ?string $type, ?string $owner, ?string $read, ?string $update, ?string $delete)`: strict full-row assertion of `type` and the four access columns
- `hashmapPath(string $path)`: normalizes to `/a/b`

Through the API (as the acting user, asserting success):

- `seedFileViaApi(string $path, string $content = '')`, `seedDirViaApi(string $path)`: `seedFileViaApi()` goes through `upload`, so with `slugNames` (default) the stored name is slugged and dots in the base name are dropped (`Foo Bar.Baz.TXT` is stored as `foo-barbaz.txt`); pass an already slugged name or `slugNames => false`. Pinned by `UploadMoveCopyTest::testUploadMultipleDotsKeepOnlyLastDotAsExtensionSeparator` and `testUploadSlugsSpacesAndUpperCaseAndLowerCasesExtension` [verified]

Assertions:

- `assertStatus(int $expected, ApiResponse $response)`: failure message contains exception and body
- `assertApiSuccess(ApiResponse $response)`: 200 and `result.success === true`
- `assertResult(bool $success, string $error, ApiResponse $response)`: 200 and exactly `{"result": {"success": ..., "error": ...}}`
- `listedNames(ApiResponse $response): array`: sorted `name`s of a `list` response (asserts 200)
- `searchHit(string $path): array`: expected shape of one `search` hit for a file (empty `thumbnail` and `mime`)

Events:

- `recordEvents(array $names, array &$log)`: records `[name, filename, errorMessage]` of the given `FileEvent::EVENT_*` of `ApiController` into `$log` (handlers are removed in `tearDown()`)

Helpers that only one test class needs stay private in that class (for example the row/disk summaries of `ListCreateRenameTest` and `UploadMoveCopyTest`, which return different shapes, `seedItem()`/`seedFolder()`, `createOutsideDir()`, `apiPostWithYiiErrorHandler()`); move one into `ApiTestCase` as soon as a second class needs the same behaviour.

Events can be observed with class-level handlers, e.g. `Event::on(ApiController::class, FileEvent::EVENT_AFTER_UPLOAD, ...)`, since the controller is created per request; `recordEvents()` does that.

## Module behaviour test authors must know

Each item is marked **[verified]** (observed in a harness run against the real code: `HarnessSmokeTest` or a probe run on 2026-11-11 with the versions above) or **[unverified]** (from reading the code only).

Dispatching:

- [verified] There is one route `filefly/api`; `ApiController::runAction()` dispatches by the parameter `action` (camelCase, converted with `Inflector::camel2id()`). A request without `action` gives 404 `Page not found.`; a route with an action id like `filefly/api/list` gives 404.
- [unverified] POST: parameters come only from the JSON raw body; `items` (for `remove`, `move`, `copy`) and `item` (for `changePermissions`) are re-encoded to JSON strings by `runAction()` and decoded in the action. (`remove` with an `items` array works [verified].)
- [verified] Upload: a POST with form field `destination` replaces all params with `['path' => destination, 'action' => 'upload']` and takes `$_FILES` as is (no `UploadedFile`).
- [verified] Verbs: `list` via GET gives 405 `Method Not Allowed`. [unverified] `download`, `stream` only GET, `search` GET or POST, everything else POST only.
- [verified] Missing action parameters give 400 `Missing required parameters: path`.

Access:

- [verified] Every non-GET request requires `can('FileflyDefault')` (role check), else 403 `Action not allowed` (`yii\web\HttpException`), before any filter runs; this also hits guests. A user with only `FileflyAdmin` gets this 403 on POST, but may GET (`stream` 200, `filefly` matches the route prefix). Pinned by `PermissionsTest::testUserWithoutFileflyDefaultIsDeniedOnEveryPostAction`.
- [verified] The GET actions `stream`, `download` and `search` skip the `FileflyDefault` role check, only the route permission applies: a user with `filefly_api` but without `FileflyDefault` can use all three (`PermissionsTest::testUserWithoutFileflyDefaultCanStreamDownloadAndSearchViaGet`). Non-GET requests need `FileflyDefault`.
- [verified] `stream` and `download` do not check the item's `access_read`: every user with the route permission gets every file, also items hidden from `list` and `search` (`PermissionsTest::testStreamIgnoresReadAccess`, `testDownloadIgnoresReadAccess`).
- [verified] Module and controller require the route permission `filefly_api_<action-id>`; `dmstr\web\User` matches route prefixes (`filefly`, `filefly_api`). `FileflyDefault` + `FileflyApi` is NOT enough for `list` (403 `You are not allowed to perform this action.`, `ForbiddenHttpException`), because `FileflyApi` only grants `filefly_api_index`. `FileflyDefault` + permission `filefly_api` works.
- [verified] Guests denied by `AccessControl` (GET): with the widget's `Accept: application/json, text/plain, */*` 403 `Login Required`; with `Accept: text/html` 302 to `http://localhost/index.php?r=user%2Fsecurity%2Flogin`.
- [verified] Root user (`actingAsAdmin()`) may do everything; `resolvePermissions` then lists all roles plus `*`.
- [verified] Non-admin read: an item whose `access_read` is a role the user has is listed, an item owned by someone else with a role the user lacks is hidden, an item with empty `access_read` inherits from its parent row (see the next point for the `repair` condition).
- [verified] An empty access column inherits from the parent row only with `repair` on (the default): `list` then repairs the children first. With `repair = false` `list` checks the children directly, so an item with an empty `access_read` is hidden, even from its owner (`PermissionsTest::testListOfDirectoryWithMixedChildAccess` against `testListOfDirectoryWithMixedChildAccessWithoutRepair`). Only `list` is pinned for `repair = false`; inheritance over several levels with `repair` on is pinned by the `...InheritanceOverThreeLevels...` tests.
- [verified] Multi-value columns do not work for reading: `access_read = 'RoleA,RoleB'` hides the item from a user with `RoleA`, because `hasPermission()` calls `can('RoleA,RoleB')`. `UpdatePermission` (`changePermissions`) writes such comma lists. The same holds for update and delete: `PermissionsTest::testCommaSeparatedUpdateValueDeniesUploadAndCreateFolder` and `testCommaSeparatedDeleteValueDeniesRemove` [verified]; the owner still sees the item (`testCommaSeparatedReadValueStillVisibleForOwner`).
- [verified] Non-admins cannot `createFolder` directly below `/` (200, `success: false`, `error: "nopermission"`): `GrantAccess` checks the new path, finds no row for it nor for `/` (the root never gets a row) and denies. Upload to `/` and `createFolder` below an existing dir row with `access_update = '*'` work. (Correction: an earlier version of this README claimed `/` is always manageable; that holds only for actions that check `/` itself, like upload to `/`.)
- [verified] `move` needs update access on the target folder and on each source item, `copy` update access on the target folder and read access on each source item (both after the self-heal of the item, the same rules and inheritance as for `rename`'s item). A denied source answers `nopermission`; in a list the items before it stay moved or copied, the denied one and the ones after it are not touched (`PermissionsTest::testMoveRequiresUpdateAccessOnEachSourceItem`, `testCopyRequiresReadAccessOnEachSourceItem`, `testMoveOfListStopsAtTheDeniedSourceKeepsEarlierItemsMovedAndLaterOnesUntouched`).
- [verified] `rename` into another folder also needs update access on that folder, checked like `createFolder` checks its parent (no self-heal, a folder without row inherits); within the same folder only the item is checked. Denial: `You are not allowed to edit this file` (`PermissionsTest::testRenameIntoOtherFolderRequiresUpdateAccessOnThatFolder`).
- [verified] `changePermissions` and `resolvePermissions` are allowed only for the owner of the item's own row (looked up with exactly the given path, ownership is not inherited), RBAC `Admin` and a directly assigned `FileflyAdmin`; roles or `*` in the access columns do not count, the root `/` is for admins only, and without a row only admins pass. The check comes before the self-heal, so a denied `changePermissions` creates no row. Denial: `changePermissions` answers `success: false`, `You are not allowed to update this record` (event `changePermissionError` only), `resolvePermissions` answers `{"auth": false}` like a missing row (`PermissionsTest::testChangePermissionsIsAllowedOnlyForOwnerAndAdmins`, `testResolvePermissionsIsAllowedOnlyForOwnerAndAdmins`). Malformed input (missing `item` or `path`) still ends in HTTP 500.
- [verified] `rootFolderManageRole` applies only to checks of the path `/` itself (upload to `/`, move into `/`), not to listing `/` or to `createFolder` below it (that checks the new path), and it is evaluated before the admin bypass: a `FileflyAdmin` without that role is denied too (`PermissionsTest::testRootFolderManageRoleIsCheckedBeforeFileflyAdminBypass`, `testRootFolderManageRoleDeniesUploadToRootWithoutRole`, `testRootFolderManageRoleDoesNotRestrictListingRootOrCreatingFoldersBelowIt`).
- [verified] Repaired rows (self-heal, `repair = true`) get `access_owner = '1'` from `defaultPermissions`, so user id 1 owns them (verified for items repaired by `list`). Rows created by create/upload get the acting user's id as owner. (Guests cannot reach create/upload at all, see `FileflyDefault` above; an earlier claim about `null` owners for guests is moot.)
- [verified] `FileManager::storage()` and `FileManager::access()` set `FileflyHashmap::$activeAccessTrait = false` and `$enableFlashMessages = false` globally; the harness restores them in `tearDown()`.

Process isolation:

- [verified by code and design, not by a failing run] `ActiveRecordAccessTrait::getUsersAuthItems()` and `dmstr\web\User::canGuest()` cache in function-static variables for the whole PHP process. Therefore every API test runs in a separate process, and the acting user cannot change after the first API call of a test: the harness throws `LogicException` [verified]. Seed state "of another user" with `seedFile()`/`seedDir()`/`seedHashmapRow()`.

Responses and errors:

- [verified] Mutating actions answer HTTP 200 with `{"result": {"success": bool, "error": string}}`, also on failure; `error` is translated via `messages/en.json` (e.g. `File already exists`), unknown keys are returned verbatim (`nopermission`, `exists` verified; `notfound` unverified), no error is `""`.
- [verified] `createFolder` on an existing target answers with the raw key `exists` (not `File already exists`) and changes no hashmap row: an existing row stays as it is, and an existing item without row gets none (`ListCreateRenameTest::testCreateFolderThatExistsAsDirectoryReportsExistsAndKeepsItsRow`, `testCreateFolderThatExistsAsDirectoryWithoutRowCreatesNoRow`, `testCreateFolderOverExistingFileWithRowKeepsTheRow`).
- [verified] `upload` and `copy` onto a path that still has a stale row (row without file) renew that row as if it had not existed: type and size of the new file, the acting user as owner, the access columns from `defaultPermissions`; no other row changes (`UploadMoveCopyTest::testUploadOntoStaleHashmapRowOfMissingFileRenewsTheRowForTheNewFile`, `testCopyOntoStaleHashmapRowOfMissingTargetRenewsTheRowForTheCopy`).
- [verified] `move`, `copy` and `rename` onto an existing file or directory (or onto the source itself) answer HTTP 200 with `success: false` and `error: "File already exists"` (key `file_already_exists`, as for upload), overwrite nothing and change no row; in a list the items before the colliding one stay moved or copied and the items after it are not tried. The action's `*Error` event fires with `errorMessage` `file_already_exists`, no `after*` event for the colliding item (`UploadMoveCopyTest::testMoveMixedListStopsAtCollidingItemWithFileAlreadyExistsAndKeepsEarlierMoves`, `testCopyOntoExistingItemFiresErrorEventAndNoAfterEventForTheCollidingItem`, `ListCreateRenameTest::testRenameOntoExistingItemFiresBeforeAndErrorEventButNoAfterEvent`). A missing source still ends in HTTP 500.
- [verified] `remove` of a missing item answers HTTP 500, not the `{success,error}` JSON, and leaves a typeless row behind (`RemoveStreamDownloadTest::testRemoveOfMissingItemReturns500AndLeavesAHashmapRowBehind`). A path without leading slash behaves differently from the slash form: the file is deleted, but the row is looked up under the literal path, so the answer is `success: false` and the row stays (`testRemoveOfPathWithoutLeadingSlashDeletesFileButReportsFailureAndKeepsRow`, `SelfHealTest::testRemoveWithoutLeadingSlashDeletesFileButKeepsRow`).
- [verified] Hashmap rows for auto-created missing folders (upload, move, copy to a missing target) and for repaired non-existent paths (rename, remove or `changePermissions` of a missing item) have `type` NULL (`UploadMoveCopyTest::testMoveToMissingTargetFolderCreatesFolderWithTypelessRow`, `testCopyToMissingFolderCreatesFolderWithTypelessRow`, `testUploadToMissingFolderCreatesFolderAndSucceeds`, `ListCreateRenameTest::testRenameMissingItemAnswers500AndRepairCreatesRowWithoutType`, `PermissionsTest::testChangePermissionsOnMissingItemAsAdminCreatesRowAndSucceeds`; for non-admins `changePermissions` creates no row, see below).
- [verified] Hashmap rows are matched byte for byte (`HashmapService::findHash()`). A target name that the database considers equal to the path of another row (MariaDB/MySQL: case, accents, trailing spaces) is refused like an existing target (`upload` 400 `File already exists`, `move`/`copy`/`rename` `File already exists`, `createFolder` `exists`), and the self-heal creates no row for such an item; on sqlite and PostgreSQL both names are independent items. The existing item's row stays unchanged on every database (`CollationCollisionTest`; the case-only cases are skipped on a case-insensitive file system such as the macOS default).
- [verified] Upload failures set HTTP 400; the body still has `success`, `error` and `uploadedFiles` (uploading an existing name: 400, `File already exists`). `uploadedFiles` holds paths without leading slash (`hello.txt`, `d/my-file.txt`).
- [verified] `list` returns `{"result": [...]}` with `name`, `dirname`, `path` (no leading slash), `urls`, `thumbnail`, `preview`, `size` (int), `date`, `type`, `extension`; `search` returns a bare JSON list of `path` (with leading slash), `type`, `extension`, `id`, `thumbnail`, `mime`; `resolvePermissions` returns `{"auth": {"read": [...], "update": [...], "delete": [...]}}`.
- [verified] `search` wraps `q` in `%...%` itself and escapes wildcards: `q=file` finds `/d/my-file.txt`, `q=%file%` finds nothing.
- [verified] Exceptions are rendered by the Yii error handler; in the test environment the body is `<pre>Name: message</pre>`, so assert on `statusCode` and `exception`, not on the body.
- [verified] `stream`: missing file gives 404 with empty message; `Range: bytes=2-4` gives 206 with the range; active content (SVG) is sent as `attachment` with `X-Content-Type-Options: nosniff` and a sandbox CSP, plain files `inline`; `Expires` and `Cache-Control: public` are set.
- [verified] `download` honours Range requests like `stream` (206, `Content-Range`): `RemoveStreamDownloadTest::testDownloadHonoursRangeRequestsLikeStream`.
- [verified] `download` works through `Yii::$app->end()` (body = file content). A missing file gives 404 with the message `File not found at path: <requested path>`, pinned by `tests/api/DownloadErrorMessageTest.php`.

Paths:

- [verified] Hashmap paths start with `/`; `list` paths do not.
- [verified] Paths containing `..` segments that leave the storage root are answered like a missing file by `stream` and `download`, pinned by the `...DotSegments...` tests in `RemoveStreamDownloadTest` and by `tests/api/StreamDownloadPathTest.php`.
- [verified] With `slugNames` (default) upload slugs the base name and lower-cases the extension: `My File.TXT` becomes `my-file.txt`.

Dependencies not declared by the module:

- [verified] `Module` needs `dmstr\web\traits\AccessBehaviorTrait` from `dmstr/yii2-web` (added to `require-dev` for the tests) and `Yii::$app->settings` from pheme/yii2-settings (replaced by `TestSettings`).
- [verified] `Yii::t('filefly', ...)` needs a message source for the category `filefly`; the harness configures one.
- [verified] `FileflyHashmap`'s `TimestampBehavior` uses `NOW()`, which sqlite lacks; the harness registers it.

Error handler and PHP diagnostics:

- [unverified] `tests/bootstrap.php` sets `YII_ENABLE_ERROR_HANDLER=false`, so Yii does not replace PHPUnit's handlers. A PHP warning or notice then becomes an exception through PHPUnit (HTTP 500 in the harness), while a deprecation is only printed and fails the isolated test process (PHPUnit 9.6 does not convert deprecations by default, `phpunit.xml` does not change that). No test pins the default path directly; the tests that need production-like handling (`resolvePermissions` with a NULL column, `changePermissions` without `item` or `path`) use the private `PermissionsTest::apiPostWithYiiErrorHandler()`, which installs a handler like Yii's that turns every reported error into an `ErrorException`.
- [verified] `cp` and `mv` of a directory in the console: copying a directory through a stream fails while the content is read. With Flysystem 1 this differed by OS (macOS raised the PHP notice `Read of 8192 bytes failed with errno=21 Is a directory`, which the harness turned into an exception, Linux returned false); the Flysystem 3 local adapter silences the notice, so on both systems the write reports failure and the action ends with exit code 1 and the output `Copy failed` / `Move failed`. An empty file named like the directory is left at the target and the source is kept. `FsControllerTest::testCpOfDirectoryFailsAndLeavesAnEmptyFileAtTheTarget` and `testMvOfDirectoryFailsKeepsSourceAndLeavesAnEmptyFileAtTheTarget` still accept both outcomes (exception, or exit code 1).

Deliberately not pinned (environment dependent):

- Case-only renames (`a.txt` to `A.txt`): the result depends on the host filesystem (case-insensitive on macOS and Windows). No test covers it [unverified].
- Search case-insensitivity depends on the database collation. `ListCreateRenameTest::testSearchMatchesCaseInsensitivelyAndAlsoViaPost` asserts it, but only against the harness's sqlite `LIKE` (case-insensitive for ASCII); a MySQL collation may behave differently, so treat that test as pinning sqlite, not production.
- Result ordering of `list` and `search`: it depends on the directory iteration order and on identical timestamps, so tests sort the results (`listResult()`, `searchPaths()`, `listedNames()`); never assert an order.
- Slugging of non-ASCII names depends on the transliteration of `yii\helpers\Inflector::slug()` (with the `intl` extension `Transliterator`, without it a built-in table). The input of `UploadMoveCopyTest::testUploadSlugsUmlautsAndRemovesDotsFromBaseName` (`Ärger Übel ß.tar.GZ`) gives `arger-ubel-sstargz` in both modes (checked by running `Inflector::slug()` with `hasIntl()` forced to false), so the test needs no `@requires extension intl`; other characters (non-Latin scripts, accents outside the built-in table) may differ.
- Timestamps: `list` pins only the format of `date`, console `ls --long` replaces directory timestamps by `TS`.
- Symlink tests need a local filesystem with symlink support.

## Console harness

`hrzg\filefly\tests\support\ConsoleTestCase` is separate from `ApiTestCase` and covers the console command `fs` (`FsController`) and `FsManager::sync()`. It boots per test a real `yii\console\Application` with the filefly module and two local filesystems rooted in fresh temp directories, mounted as the schemes `a://` and `b://` (`filesystemComponents`); the controller id `fs` is mapped to a `CapturingFsController` through `controllerMap`.

- `runFs(string $action, array $args = [], array $options = []): ConsoleResult`: runs `fs/<action>` non-interactively (`confirm()` is answered with yes); `ConsoleResult` has `exitCode`, `stdout`, `stderr` and `exception` (class name of a thrown exception, the exit code is then `null`). Tests assert only that an action threw, never the exception class (neither the module's nor Flysystem's).
- `fsManager(): FsManager`: a fresh `FsManager` bound to the module, to call `sync()` directly.
- Disk helpers take the scheme first, paths are relative to the root of that filesystem: `seedFile($scheme, $path, $content = '', ?$mtime = null)`, `seedDir()`, `touchPath()`, `mtime()`, `readFromDisk()`, `diskPath()`, `hash()`.
- Tree helpers: `tree($scheme)` is the complete tree of one root as sorted `path => sha1` map (directories are `ConsoleTestCase::DIR`, paths have no leading slash); `assertTree($scheme, $expectedContents)` takes plain file contents; `snapshot()` captures both roots and `assertTreeUnchanged($snapshot)` compares them again.
- `filesystemComponentConfig(string $root)` is the single Flysystem-version specific part of the harness (`LocalFilesystem` of creocoder/yii2-flysystem 2, configured like `fsTest` of the API harness); change only this method when the adapter changes.
- No process isolation: the console code has no function-static caches (the API harness needs one process per test because the permission code caches the user's auth items that way), and `tearDown()` drops `Yii::$app` and resets the DI container, so tests run in one process. Mtimes are set with `touch()`, never with `sleep()`.
- `CapturingFsController` only redirects `stdout()` and `stderr()` into static buffers (reset by `runFs()`); everything else is the real `FsController`.
- Never assume a listing order: it is the order of the adapter, so tests compare entries sorted or as a multiset of tokens.

Run only the console tests with `vendor/bin/phpunit tests/console`.

Not exercised through the API: a `Guest` RBAC role (guests are tested without one). The migrations run only in the real-database mode below; the default sqlite run creates the final table schema directly.

## Real databases

Without environment variables the API tests run on sqlite in memory, exactly as described above. With `FILEFLY_TEST_DB_DSN` set, `hrzg\filefly\tests\support\TestDatabase` switches the `db` component of `ApiTestCase` to that database:

- once per PHPUnit run (`tests/bootstrap.php`, in the parent process; the isolated test processes inherit `FILEFLY_TEST_DB_PREPARED`) the table `filefly_hashmap` and the migration history table `migration` are dropped and all nine module migrations are applied with Yii's `migrate/up` (`MigrateController` with `migrationPath` `@hrzg/filefly/migrations`, the RBAC migrations against a throwaway `PhpManager`), so the table is the one an application gets
- every test starts with `DELETE FROM filefly_hashmap`; measured per reset with a fresh connection: MariaDB 10.6 0.3 ms (TRUNCATE 0.8 ms), MySQL 8.4 0.4 ms (2.1 ms), PostgreSQL 16 0.4 ms (0.8 ms). A full run takes about 85 s on MariaDB/MySQL and 100 s on PostgreSQL against 75 to 80 s on sqlite
- the RBAC setup per test is unchanged (`PhpManager` filled by the RBAC migrations)

Variables: `FILEFLY_TEST_DB_DSN` (PDO DSN; the database must be a throwaway one, the run drops tables in it), `FILEFLY_TEST_DB_USER`, `FILEFLY_TEST_DB_PASSWORD`, `FILEFLY_TEST_DB_CHARSET` (connection charset, default `utf8mb4` for MySQL, `utf8` otherwise), `FILEFLY_TEST_DB_INIT_SQL` (statement run on every new connection, for example an SQL mode), `FILEFLY_TEST_DB_VERBOSE=1` (print the migration output).

`./db/compose.yaml` (Compose project `filefly-db`) has throwaway servers with data on tmpfs, published only to ephemeral ports on 127.0.0.1: `mariadb106` (`mariadb:10.6.24`, the production line), `mariadb118` (`mariadb:11.8.5`, newest MariaDB LTS), `mysql84` (`mysql:8.4.7`), `postgres16` (`postgres:16.11`). The test-only credentials are in `./db/test-credentials.env`. `./db/run.sh` reads the published port and runs PHPUnit on the host:

```bash
docker compose -p filefly-db -f tests/db/compose.yaml up -d --wait mariadb106 postgres16
./tests/db/run.sh mariadb106
./tests/db/run.sh postgres16 tests/api
FILEFLY_TEST_DB_INIT_SQL="SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')" \
  ./tests/db/run.sh mysql84 tests/api
eval "$(./tests/db/run.sh --env postgres16)"   # only export the variables
docker compose -p filefly-db -f tests/db/compose.yaml down --volumes --remove-orphans
```

Tests that pin sqlite or MySQL behaviour rather than a module guarantee (results of 2026-11-11, all servers above; everything else is green everywhere):

- PostgreSQL: `search` is case-sensitive there (`LIKE`), so `ListCreateRenameTest::testSearchMatchesCaseInsensitivelyAndAlsoViaPost`, `testSearchOnlyFilesZeroMixesFilesAndDirectories`, `testSearchLimitZeroMeansNoLimit`, `testSearchBasePathRestrictsResultsToPathPrefix` and `testSearchBasePathDoesNotEscapeLikeWildcards` fail: their fixtures contain `/docs/Report-2.PDF`, which `q=report` matches only case-insensitively
- MySQL 8.4 with `NO_BACKSLASH_ESCAPES`: Yii's backslash-escaped `LIKE` patterns match nothing, so `ListCreateRenameTest::testSearchEscapesPercentSignInQuery` and `testSearchTreatsUnderscoreInQueryLiterally` fail (MariaDB keeps the backslash as `LIKE` escape in that mode and passes)

The suite seeds only paths that are distinct under every collation. Known differences it does not cover, found with temporary probes through this harness (Linux, case-sensitive filesystem) [verified]:

- MariaDB/MySQL: the migrations convert the table to `utf8` (`utf8mb3`) with the server's default collation for it (`utf8mb3_general_ci` on MariaDB 10.6 and MySQL 8.4, `utf8mb3_uca1400_ai_ci` on MariaDB 11.8), which is case- and accent-insensitive and PAD SPACE. Paths that differ only in case, accents or trailing spaces (`/Docs` and `/docs`, `/a.txt` and `/ä.txt`, `/x` and `/x `) are equal for the database; rows are matched byte for byte, so the second name is refused or stays without row (pinned by `CollationCollisionTest`, see "Responses and errors")
- MariaDB/MySQL: a name with a 4-byte UTF-8 character (emoji) cannot be stored in the `utf8mb3` column; the request fails with HTTP 500 and the file stays on disk without row, and from then on every `list` of that folder fails with HTTP 500 (self-heal tries to store the row)
- all databases: `path` is `varchar(255)`; the model's validator rejects longer paths, so an upload or rename that produces one reports success, but the new path gets no row (rename: child rows that would become too long stay under the old path)

