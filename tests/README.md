<!-- file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC] -->

# Tests

## Install and run

`yiisoft/yii2` needs bower assets, so `composer.json` declares the asset-packagist repository. `league/flysystem` 1.x is affected by advisory `PKSA-w9tt-7782-78jx` (CVE-2026-102601, all versions `<=3.35.2`, no 1.x fix), which Composer >= 2.9 blocks; `composer.json` ignores exactly this advisory in `config.audit.ignore` until the Flysystem 3 migration removes the package.

```bash
composer install
vendor/bin/phpunit
```

Run only the API characterisation tests:

```bash
vendor/bin/phpunit tests/api
```

Tested with PHP 8.5.1, Composer 2.9.2, PHPUnit 9.6.38, yiisoft/yii2 2.0.55, league/flysystem 1.1.10, creocoder/yii2-flysystem 1.2.0, dmstr/yii2-active-record-permissions 1.3.2, dmstr/yii2-web 1.1.3.

Every test in `tests/api/` runs in its own PHP process (see "Process isolation" below); one isolated test takes roughly 0.05 to 0.1 s.

`tests/bootstrap.php` compiles all classes of league/flysystem 1.x with `E_DEPRECATED` masked: on PHP >= 8.4 they raise compile-time "implicitly nullable parameter" deprecations, which an isolated process prints to stderr and PHPUnit turns into a test error. Deprecations from any other code (including the module) still fail the test.

## Harness

`hrzg\filefly\tests\support\ApiTestCase` boots per test a real `yii\web\Application` with:

- module `filefly` (`hrzg\filefly\Module`, `filesystem => 'fsTest'`)
- `db`: sqlite in memory, table `filefly_hashmap` created in its final migrated form (the migrations themselves use `alterColumn()`, which sqlite does not support); MySQL's `NOW()` (used by the model's `TimestampBehavior`) is registered as sqlite function
- `fsTest`: `creocoder\flysystem\LocalFilesystem` rooted in a fresh temp directory (`$this->fsRoot`); this config lives only in `filesystemComponentConfig()`
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
- `createRole(string $name, array $children = []): Role`, `createPermission(string $name): Permission`

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
- `assertFileOnDisk(string $path, ?string $expectedContent = null)`, `assertDirOnDisk(string $path)`, `assertNotOnDisk(string $path)`

Hashmap:

- `seedHashmapRow(string $path, string $type, array $access = [], ?int $size = null)`: row without model or access checks, access columns default to the module's `defaultPermissions`
- `hashmapRow(string $path): ?array`, `hashmapRows(?string $prefix = null): array`: all values as strings (like MySQL)
- `assertHashmapRow(string $path, array $expected = [])`, `assertNoHashmapRow(string $path)`
- `hashmapPath(string $path)`: normalizes to `/a/b`

Through the API (as the acting user, asserting success):

- `seedFileViaApi(string $path, string $content = '')`, `seedDirViaApi(string $path)`

Assertions:

- `assertStatus(int $expected, ApiResponse $response)`: failure message contains exception and body
- `assertApiSuccess(ApiResponse $response)`: 200 and `result.success === true`

Events can be observed with class-level handlers, e.g. `Event::on(ApiController::class, FileEvent::EVENT_AFTER_UPLOAD, ...)`, since the controller is created per request.

## Module behaviour test authors must know

Each item is marked **[verified]** (observed in a harness run against the real code: `HarnessSmokeTest` or a probe run on 2026-11-11 with the versions above) or **[unverified]** (from reading the code only).

Dispatching:

- [verified] There is one route `filefly/api`; `ApiController::runAction()` dispatches by the parameter `action` (camelCase, converted with `Inflector::camel2id()`). A request without `action` gives 404 `Page not found.`; a route with an action id like `filefly/api/list` gives 404.
- [unverified] POST: parameters come only from the JSON raw body; `items` (for `remove`, `move`, `copy`) and `item` (for `changePermissions`) are re-encoded to JSON strings by `runAction()` and decoded in the action. (`remove` with an `items` array works [verified].)
- [verified] Upload: a POST with form field `destination` replaces all params with `['path' => destination, 'action' => 'upload']` and takes `$_FILES` as is (no `UploadedFile`).
- [verified] Verbs: `list` via GET gives 405 `Method Not Allowed`. [unverified] `download`, `stream` only GET, `search` GET or POST, everything else POST only.
- [verified] Missing action parameters give 400 `Missing required parameters: path`.

Access:

- [verified] Every non-GET request requires `can('FileflyDefault')` (role check), else 403 `Action not allowed` (`yii\web\HttpException`), before any filter runs; this also hits guests. A user with only `FileflyAdmin` gets this 403 on POST, but may GET (`stream` 200, `filefly` matches the route prefix).
- [verified] Module and controller require the route permission `filefly_api_<action-id>`; `dmstr\web\User` matches route prefixes (`filefly`, `filefly_api`). `FileflyDefault` + `FileflyApi` is NOT enough for `list` (403 `You are not allowed to perform this action.`, `ForbiddenHttpException`), because `FileflyApi` only grants `filefly_api_index`. `FileflyDefault` + permission `filefly_api` works.
- [verified] Guests denied by `AccessControl` (GET): with the widget's `Accept: application/json, text/plain, */*` 403 `Login Required`; with `Accept: text/html` 302 to `http://localhost/index.php?r=user%2Fsecurity%2Flogin`.
- [verified] Root user (`actingAsAdmin()`) may do everything; `resolvePermissions` then lists all roles plus `*`.
- [verified] Non-admin read: an item whose `access_read` is a role the user has is listed, an item owned by someone else with a role the user lacks is hidden, an item with empty `access_read` inherits from its parent row.
- [verified] Multi-value columns do not work for reading: `access_read = 'RoleA,RoleB'` hides the item from a user with `RoleA`, because `hasPermission()` calls `can('RoleA,RoleB')`. `UpdatePermission` (`changePermissions`) writes such comma lists. [unverified] the same for update/delete.
- [verified] Non-admins cannot `createFolder` directly below `/` (200, `success: false`, `error: "nopermission"`): `GrantAccess` checks the new path, finds no row for it nor for `/` (the root never gets a row) and denies. Upload to `/` and `createFolder` below an existing dir row with `access_update = '*'` work. (Correction: an earlier version of this README claimed `/` is always manageable; that holds only for actions that check `/` itself, like upload to `/`.)
- [unverified] `rootFolderManageRole` restricts non-read access to `/`.
- [verified] Repaired rows (self-heal, `repair = true`) get `access_owner = '1'` from `defaultPermissions`, so user id 1 owns them (verified for items repaired by `list`). Rows created by create/upload get the acting user's id as owner. (Guests cannot reach create/upload at all, see `FileflyDefault` above; an earlier claim about `null` owners for guests is moot.)
- [verified] `FileManager::fileSystem()` sets `FileflyHashmap::$activeAccessTrait = false` and `$enableFlashMessages = false` globally; the harness restores them in `tearDown()`.

Process isolation:

- [verified by code and design, not by a failing run] `ActiveRecordAccessTrait::getUsersAuthItems()` and `dmstr\web\User::canGuest()` cache in function-static variables for the whole PHP process. Therefore every API test runs in a separate process, and the acting user cannot change after the first API call of a test: the harness throws `LogicException` [verified]. Seed state "of another user" with `seedFile()`/`seedDir()`/`seedHashmapRow()`.

Responses and errors:

- [verified] Mutating actions answer HTTP 200 with `{"result": {"success": bool, "error": string}}`, also on failure; `error` is translated via `messages/en.json` (e.g. `File already exists`), unknown keys are returned verbatim (`nopermission`, `exists` verified; `notfound` unverified), no error is `""`.
- [verified] Upload failures set HTTP 400; the body still has `success`, `error` and `uploadedFiles` (uploading an existing name: 400, `File already exists`). `uploadedFiles` holds paths without leading slash (`hello.txt`, `d/my-file.txt`).
- [verified] `list` returns `{"result": [...]}` with `name`, `dirname`, `path` (no leading slash), `urls`, `thumbnail`, `preview`, `size` (int), `date`, `type`, `extension`; `search` returns a bare JSON list of `path` (with leading slash), `type`, `extension`, `id`, `thumbnail`, `mime`; `resolvePermissions` returns `{"auth": {"read": [...], "update": [...], "delete": [...]}}`.
- [verified] `search` wraps `q` in `%...%` itself and escapes wildcards: `q=file` finds `/d/my-file.txt`, `q=%file%` finds nothing.
- [verified] Exceptions are rendered by the Yii error handler; in the test environment the body is `<pre>Name: message</pre>`, so assert on `statusCode` and `exception`, not on the body.
- [verified] `stream`: missing file gives 404 with empty message; `Range: bytes=2-4` gives 206 with the range; active content (SVG) is sent as `attachment` with `X-Content-Type-Options: nosniff` and a sandbox CSP, plain files `inline`; `Expires` and `Cache-Control: public` are set.
- [verified] `download` works through `Yii::$app->end()` (body = file content). A missing file gives 404 whose message leaks the absolute disk path (`SplFileInfo::getType(): Lstat failed for /.../fs/nope.txt`); characterise only the status code, the message is Flysystem 1 specific.

Paths:

- [verified] Hashmap paths start with `/`; `list` paths do not.
- [verified] With `slugNames` (default) upload slugs the base name and lower-cases the extension: `My File.TXT` becomes `my-file.txt`.

Dependencies not declared by the module:

- [verified] `Module` needs `dmstr\web\traits\AccessBehaviorTrait` from `dmstr/yii2-web` (added to `require-dev` for the tests) and `Yii::$app->settings` from pheme/yii2-settings (replaced by `TestSettings`).
- [verified] `Yii::t('filefly', ...)` needs a message source for the category `filefly`; the harness configures one.
- [verified] `FileflyHashmap`'s `TimestampBehavior` uses `NOW()`, which sqlite lacks; the harness registers it.

Not yet exercised at all: `move`, `copy`, `rename`, `changePermissions`, recursive delete (`deleteRecursive`), `mime-whitelist`, upload/content validators via the API, `rootFolderManageRole`, a `Guest` RBAC role, callbacks (`urlCallback`, `thumbnailCallback`, `previewCallback`), events.
