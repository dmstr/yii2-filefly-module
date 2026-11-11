<?php
// file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC]

namespace hrzg\filefly\tests\support;

use hrzg\filefly\controllers\ApiController;
use hrzg\filefly\events\FileEvent;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\Event;
use yii\base\ExitException;
use yii\db\Query;
use yii\di\Container;
use yii\web\Application;

/**
 * Base test case for characterisation tests of the filefly HTTP API (ApiController).
 *
 * Per test it boots a real \yii\web\Application with the filefly module, an sqlite in-memory `db`,
 * a PhpManager `authManager` filled by the module's own RBAC migrations, a \dmstr\web\User `user`
 * component and a local filesystem component `fsTest` rooted in a fresh temp directory.
 *
 * Every API call goes through Application::run() with a fresh request and response,
 * i.e. URL routing, ApiController::runAction(), module and controller filters (access, verbs, cors)
 * and the error handler run exactly as in production. Only the output is captured (TestResponse).
 *
 * Each test runs in a separate PHP process, because the permission code caches the current user's
 * auth items in function-static variables that cannot be reset (see tests/README.md).
 *
 * Public helper API (stable, see tests/README.md):
 * - acting as: actingAsAdmin(), actingAsUser(), actingAsGuest(), actingAsFileflyUser(), actAsApiUser(),
 *   createRole(), createRoles(), createPermission()
 * - module: configureModule(), setSetting()
 * - requests: apiGet(), apiPost(), apiUpload(), apiStream(), apiDownload(), request()
 * - disk: seedFile(), seedDir(), diskPath(), readFromDisk(), disk(), assertFileOnDisk(), assertDirOnDisk(),
 *   assertNotOnDisk()
 * - hashmap: seedHashmapRow(), seedRow(), hashmapRow(), hashmapRows(), rowPaths(), assertHashmapRow(),
 *   assertAccess(), assertNoHashmapRow()
 * - API seeding: seedFileViaApi(), seedDirViaApi()
 * - assertions: assertStatus(), assertApiSuccess(), assertResult(), listedNames(), searchHit()
 */
abstract class ApiTestCase extends TestCase
{
    /** yii component id of the filesystem under test */
    public const FS_COMPONENT = 'fsTest';

    /** module id, the API route is `filefly/api` */
    public const MODULE_ID = 'filefly';

    /** id and username of the root user (\dmstr\web\User::$rootUsers), mirrors the phd5 `admin` user */
    public const ADMIN_ID = 1;
    public const ADMIN_USERNAME = 'admin';

    /** id of the plain (non-root) acting user used by actAsApiUser() and most permission tests */
    public const USER_ID = 5;

    /** id (string, like the hashmap column) of "someone else", the owner of rows seeded for other users */
    public const OTHER_ID = '7';

    /** Accept header sent by the filemanager widget (angular $http default) */
    public const DEFAULT_ACCEPT = 'application/json, text/plain, */*';

    /**
     * @var string fresh working directory of this test (filesystem root, rbac files, upload temp files)
     */
    protected $workDir;

    /**
     * @var string root directory of the filesystem component, API path `/` maps here
     */
    protected $fsRoot;

    /**
     * @var array module properties applied on top of the defaults, see configureModule()
     */
    private $_moduleOverrides = [];

    /**
     * @var bool whether an API call was made in this test
     */
    private $_apiCalled = false;

    /**
     * @var int counter for upload temp files
     */
    private $_uploadCounter = 0;

    /**
     * @var array superglobals saved in setUp()
     */
    private $_savedGlobals = [];

    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        // function-static caches in ActiveRecordAccessTrait::getUsersAuthItems() and
        // \dmstr\web\User::canGuest() survive the application, so every test needs its own process
        $this->setRunTestInSeparateProcess(true);
        $this->setPreserveGlobalState(false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->_savedGlobals = [$_GET, $_POST, $_FILES, $_SERVER];

        $this->workDir = $this->createWorkDir();
        $this->fsRoot = $this->workDir . '/fs';
        foreach ([$this->fsRoot, $this->workDir . '/rbac', $this->workDir . '/uploads', $this->workDir . '/runtime'] as $dir) {
            mkdir($dir, 0755, true);
        }

        new Application($this->applicationConfig());

        $this->createHashmapSchema();
        $this->installRbac();
        $this->actingAsGuest();
    }

    protected function tearDown(): void
    {
        // FileManager::fileSystem() switches these off globally
        FileflyHashmap::$activeAccessTrait = true;
        FileflyHashmap::$enableFlashMessages = true;

        Event::offAll();

        if (Yii::$app !== null && Yii::$app->has('db', true)) {
            Yii::$app->db->close();
        }
        Yii::$app = null;
        Yii::$container = new Container();

        [$_GET, $_POST, $_FILES, $_SERVER] = $this->_savedGlobals;

        $this->removeWorkDir();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // application setup (the only place with Flysystem specific config)
    // ------------------------------------------------------------------

    /**
     * Configuration of the filesystem component under test.
     * This is the single Flysystem-version specific part of the harness.
     */
    protected function filesystemComponentConfig(): array
    {
        return [
            'class' => \creocoder\flysystem\LocalFilesystem::class,
            'path' => $this->fsRoot,
            // directories as Flysystem 1 created them (0755); creocoder's default is 0740
            'config' => ['directory_visibility' => 'public'],
        ];
    }

    /**
     * Default module configuration, override in a test class or use configureModule() per test
     */
    protected function moduleConfig(): array
    {
        return [
            'class' => Module::class,
            'filesystem' => self::FS_COMPONENT,
        ];
    }

    protected function applicationConfig(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        return [
            'id' => 'filefly-test',
            'basePath' => $this->workDir,
            'runtimePath' => $this->workDir . '/runtime',
            'vendorPath' => $repoRoot . '/vendor',
            'language' => 'en-US',
            'aliases' => [
                // registered by yiisoft/yii2-composer when the module is installed as a dependency
                '@hrzg/filefly' => $repoRoot,
                '@web' => '/',
                '@webroot' => $this->workDir,
            ],
            'modules' => [
                self::MODULE_ID => $this->moduleConfig(),
            ],
            'components' => [
                'db' => $this->dbConfig(),
                self::FS_COMPONENT => $this->filesystemComponentConfig(),
                'authManager' => [
                    'class' => \yii\rbac\PhpManager::class,
                    'itemFile' => $this->workDir . '/rbac/items.php',
                    'assignmentFile' => $this->workDir . '/rbac/assignments.php',
                    'ruleFile' => $this->workDir . '/rbac/rules.php',
                ],
                'user' => [
                    'class' => \dmstr\web\User::class,
                    'identityClass' => TestIdentity::class,
                    'enableSession' => false,
                    'rootUsers' => [self::ADMIN_USERNAME],
                    'enableRootWarningFlash' => false,
                    'loginUrl' => ['/user/security/login'],
                ],
                'settings' => [
                    'class' => TestSettings::class,
                ],
                'request' => $this->requestConfig('GET', [], ''),
                'response' => ['class' => TestResponse::class],
                'errorHandler' => [
                    // keep PHPUnit's output buffers, never exit
                    'discardExistingOutput' => false,
                    'silentExitOnException' => true,
                ],
                'urlManager' => [
                    'enablePrettyUrl' => false,
                ],
                'i18n' => [
                    'translations' => [
                        'filefly' => [
                            'class' => \yii\i18n\PhpMessageSource::class,
                            'sourceLanguage' => 'en-US',
                            'basePath' => '@hrzg/filefly/messages',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * sqlite in-memory database. MySQL's NOW(), used by FileflyHashmap's TimestampBehavior,
     * is registered as a user function.
     */
    private function dbConfig(): array
    {
        $config = [
            'class' => \yii\db\Connection::class,
            'dsn' => 'sqlite::memory:',
            'on afterOpen' => static function ($event) {
                $pdo = $event->sender->pdo;
                $now = static function () {
                    return date('Y-m-d H:i:s');
                };
                if (method_exists($pdo, 'createFunction')) {
                    $pdo->createFunction('NOW', $now, 0);
                } else {
                    $pdo->sqliteCreateFunction('NOW', $now, 0);
                }
            },
        ];
        if (class_exists(\Pdo\Sqlite::class)) {
            // PHP >= 8.4: driver specific class, PDO::sqliteCreateFunction() is deprecated in 8.5
            $config['pdoClass'] = \Pdo\Sqlite::class;
        }
        return $config;
    }

    /**
     * Creates `filefly_hashmap` as it looks after all migrations in migrations/.
     *
     * The migrations themselves do not run on sqlite: m170815_120101 and m230606_* use
     * alterColumn(), which yii\db\sqlite\QueryBuilder does not support.
     */
    private function createHashmapSchema(): void
    {
        $db = Yii::$app->db;
        $db->createCommand()->createTable('filefly_hashmap', [
            'id' => 'pk',
            'component' => 'string(45) NOT NULL',
            'type' => 'string(32) NULL',
            'path' => 'string(255) NOT NULL',
            'size' => 'bigint NULL',
            'access_owner' => 'string(255)',
            'access_read' => 'string(255)',
            'access_update' => 'string(255)',
            'access_delete' => 'string(255)',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ])->execute();
        $db->createCommand()->createIndex(
            'unique_filefly_hashmap_component_path',
            'filefly_hashmap',
            ['component', 'path'],
            true
        )->execute();
    }

    /**
     * Runs the module's own RBAC migrations against the PhpManager:
     * FileflyAdmin (-> filefly, FileflyPermissions), FileflyApi (-> filefly_api_index),
     * FileflyDefault (-> filefly_default_index), FileflyPermissions
     */
    private function installRbac(): void
    {
        $migrationDir = dirname(__DIR__, 2) . '/migrations';
        $migrations = [
            'm160619_070000_module_access',
            'm160619_080000_api_access',
            'm160619_090000_default_access',
            'm160629_090000_permission_access',
        ];
        foreach ($migrations as $class) {
            require_once $migrationDir . '/' . $class . '.php';
            (new $class())->up();
        }
    }

    private function requestConfig(string $method, array $query, string $rawBody): array
    {
        return [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'filefly-test',
            'scriptUrl' => '/index.php',
            'hostInfo' => 'http://localhost',
            'url' => '/index.php' . ($query === [] ? '' : '?' . http_build_query($query)),
            'queryParams' => $query,
            'rawBody' => $rawBody,
        ];
    }

    // ------------------------------------------------------------------
    // acting as
    // ------------------------------------------------------------------

    /**
     * Root user (username `admin`, id 1): \dmstr\web\User::can() returns true for everything.
     * This is how the admin of a phd5 installation works; FileflyHashmap::getUsersAuthItems()
     * then contains all roles, so GrantAccess grants everything.
     */
    protected function actingAsAdmin(): void
    {
        $this->actAs(new TestIdentity(['id' => self::ADMIN_ID, 'username' => self::ADMIN_USERNAME]));
    }

    /**
     * Authenticated non-root user with the given roles and permissions assigned (direct assignments).
     * Roles must exist (module roles: Module::ACCESS_ROLE_*, or created via createRole()),
     * permissions are created when missing (e.g. route permissions like `filefly_api`).
     *
     * Do not use id 1 unless intended: the module's default `access_owner` for repaired hashmap rows is `1`.
     *
     * @param int|string $id
     * @param string[] $roles
     * @param string[] $permissions
     */
    protected function actingAsUser($id, array $roles = [], array $permissions = [], ?string $username = null): void
    {
        $auth = Yii::$app->authManager;
        foreach ($roles as $roleName) {
            $role = $auth->getRole($roleName);
            if ($role === null) {
                throw new \InvalidArgumentException("Role '$roleName' does not exist, create it with createRole()");
            }
            $auth->assign($role, $id);
        }
        foreach ($permissions as $permissionName) {
            $auth->assign($this->createPermission($permissionName), $id);
        }
        $this->actAs(new TestIdentity(['id' => $id, 'username' => $username ?? 'user' . $id]));
    }

    /**
     * Plain API user: role `FileflyDefault` and the route permission `filefly_api`, nothing else
     */
    protected function actingAsFileflyUser(int $id): void
    {
        $this->actingAsUser($id, ['FileflyDefault'], ['filefly_api']);
    }

    /**
     * Creates the roles `RoleA`, `RoleB` and `Secret` (if missing); they are assigned to nobody by default
     */
    protected function createRoles(): void
    {
        foreach (['RoleA', 'RoleB', 'Secret'] as $role) {
            $this->createRole($role);
        }
    }

    /**
     * Acting user USER_ID with FileflyDefault, the route permission `filefly_api` and RoleA (+ extra roles);
     * creates the roles of createRoles() first
     *
     * @param string[] $extraRoles existing roles that are assigned in addition
     */
    protected function actAsApiUser(array $extraRoles = []): void
    {
        $this->createRoles();
        $this->actingAsUser(self::USER_ID, array_merge(['FileflyDefault', 'RoleA'], $extraRoles), ['filefly_api']);
    }

    /**
     * No identity. Guest permissions come from the RBAC role `Guest` (\dmstr\web\User::GUEST_ROLE),
     * which does not exist by default.
     */
    protected function actingAsGuest(): void
    {
        $this->actAs(null);
    }

    /**
     * Creates a role (if missing) and adds the given existing roles/permissions as children
     *
     * @param string[] $children
     */
    protected function createRole(string $name, array $children = []): \yii\rbac\Role
    {
        $auth = Yii::$app->authManager;
        $role = $auth->getRole($name);
        if ($role === null) {
            $role = $auth->createRole($name);
            $auth->add($role);
        }
        foreach ($children as $childName) {
            $child = $auth->getRole($childName) ?? $auth->getPermission($childName) ?? $this->createPermission($childName);
            if (!$auth->hasChild($role, $child)) {
                $auth->addChild($role, $child);
            }
        }
        return $role;
    }

    /**
     * Creates a permission if missing
     */
    protected function createPermission(string $name): \yii\rbac\Permission
    {
        $auth = Yii::$app->authManager;
        $permission = $auth->getPermission($name);
        if ($permission === null) {
            $permission = $auth->createPermission($name);
            $auth->add($permission);
        }
        return $permission;
    }

    private function actAs(?TestIdentity $identity): void
    {
        if ($this->_apiCalled) {
            throw new \LogicException(
                'The acting user must be chosen before the first API call of a test: '
                . 'FileflyHashmap::getUsersAuthItems() caches the auth items of the first user for the whole process. '
                . 'Seed state for other users with seedFile()/seedDir()/seedHashmapRow() instead.'
            );
        }
        Yii::$app->user->setIdentity($identity);
    }

    // ------------------------------------------------------------------
    // module configuration
    // ------------------------------------------------------------------

    /**
     * Sets module properties (e.g. `repair`, `slugNames`, `deleteRecursive`, `defaultPermissions`,
     * `allowedExtensions`, `rootFolderManageRole`, callbacks). Applied to the module instance of
     * every following API call; the module is recreated per call like in production.
     */
    protected function configureModule(array $properties): void
    {
        $this->_moduleOverrides = array_merge($this->_moduleOverrides, $properties);
    }

    /**
     * Sets a value in the `settings` component, e.g. setSetting('mime-whitelist', 'image/png,text/plain')
     */
    protected function setSetting(string $key, $value, string $section = 'filefly'): void
    {
        Yii::$app->settings->set($key, $value, $section);
    }

    // ------------------------------------------------------------------
    // requests
    // ------------------------------------------------------------------

    /**
     * GET `filefly/api` with `action` and params as query string (stream, download, search)
     */
    protected function apiGet(string $action, array $params = [], array $headers = []): ApiResponse
    {
        return $this->request('GET', ['action' => $action] + $params, null, [], [], $headers);
    }

    /**
     * POST `filefly/api` with `action` and params as JSON body, like the filemanager widget.
     * Use the API action names as sent by the widget, e.g. `list`, `createFolder`, `changePermissions`.
     * Arrays like `items` are passed as arrays, the controller re-encodes them itself.
     */
    protected function apiPost(string $action, array $params = [], array $headers = []): ApiResponse
    {
        $body = json_encode(['action' => $action] + $params, JSON_THROW_ON_ERROR);
        return $this->request('POST', [], $body, [], [], $headers + ['Content-Type' => 'application/json;charset=utf-8']);
    }

    /**
     * Multipart upload to `filefly/api` (form field `destination`, files in $_FILES as `file-0`, `file-1`, ...)
     *
     * @param string $destination target directory as sent by the widget, e.g. `/` or `/docs`
     * @param array $files either [fileName => content] or a list of
     *                     ['name' => ..., 'content' => ..., 'type' => mime (optional), 'error' => UPLOAD_ERR_* (optional)]
     */
    protected function apiUpload(string $destination, array $files, array $headers = []): ApiResponse
    {
        $filesArray = [];
        $index = 0;
        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                $file = ['name' => (string)$key, 'content' => (string)$file];
            }
            $tmpName = $this->workDir . '/uploads/php' . (++$this->_uploadCounter);
            file_put_contents($tmpName, $file['content'] ?? '');
            $filesArray['file-' . $index++] = [
                'name' => $file['name'],
                'type' => $file['type'] ?? 'application/octet-stream',
                'tmp_name' => $tmpName,
                'error' => $file['error'] ?? UPLOAD_ERR_OK,
                'size' => strlen($file['content'] ?? ''),
            ];
        }
        return $this->request(
            'POST',
            [],
            '',
            ['destination' => $destination],
            $filesArray,
            $headers + ['Content-Type' => 'multipart/form-data; boundary=filefly-test-boundary']
        );
    }

    /**
     * GET stream action; the body holds the streamed bytes, headers the delivery headers
     */
    protected function apiStream(string $path, array $headers = []): ApiResponse
    {
        return $this->apiGet('stream', ['path' => $path], $headers);
    }

    /**
     * GET download action (ends with Yii::$app->end(), which is caught by the harness)
     */
    protected function apiDownload(string $path, array $headers = []): ApiResponse
    {
        return $this->apiGet('download', ['path' => $path], $headers);
    }

    /**
     * Low-level request through Application::run(). Every call gets a fresh request, response,
     * module instance and filesystem component; db, authManager, user and settings persist within the test.
     *
     * @param array $query query params (route param `r` is added)
     * @param string|null $rawBody raw request body
     * @param array $post $_POST
     * @param array $files $_FILES
     * @param array $headers request headers, `Accept` defaults to DEFAULT_ACCEPT
     */
    protected function request(string $method, array $query, ?string $rawBody, array $post = [], array $files = [], array $headers = []): ApiResponse
    {
        $this->_apiCalled = true;
        $app = Yii::$app;

        $query = ['r' => self::MODULE_ID . '/api'] + $query;
        $headers += ['Accept' => self::DEFAULT_ACCEPT];

        $_GET = $query;
        $_POST = $post;
        $_FILES = $files;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query($query);
        $_SERVER['QUERY_STRING'] = http_build_query($query);
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';
        unset($_SERVER['CONTENT_TYPE']);
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $_SERVER['CONTENT_TYPE'] = $value;
            }
        }

        $app->set('request', $this->requestConfig($method, $query, (string)$rawBody));
        $requestHeaders = $app->getRequest()->getHeaders();
        foreach ($headers as $name => $value) {
            $requestHeaders->set($name, $value);
        }
        $app->set('response', ['class' => TestResponse::class]);
        $app->set(self::FS_COMPONENT, $this->filesystemComponentConfig());
        $app->setModule(self::MODULE_ID, array_merge($this->moduleConfig(), $this->_moduleOverrides));
        $app->controller = null;
        $app->requestedAction = null;
        $app->requestedRoute = null;
        $app->requestedParams = null;

        $exception = null;
        try {
            $app->run();
        } catch (ExitException $e) {
            // Application::end() (download action) already sent the response
        } catch (\Throwable $e) {
            // in production the registered exception handler renders the error
            $exception = $e;
            $app->getErrorHandler()->handleException($e);
        }

        /** @var TestResponse $response */
        $response = $app->getResponse();
        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = (array)$values;
        }

        return new ApiResponse($response->getStatusCode(), $responseHeaders, (string)$response->capturedBody, $exception);
    }

    // ------------------------------------------------------------------
    // filesystem (on disk)
    // ------------------------------------------------------------------

    /**
     * Whole test filesystem as `/path => file content` (`null` for directories), sorted by path
     */
    protected function disk(): array
    {
        $tree = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fsRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            $path = substr($info->getPathname(), strlen($this->fsRoot));
            $tree[$path] = $info->isDir() ? null : file_get_contents($info->getPathname());
        }
        ksort($tree, SORT_STRING);
        return $tree;
    }

    /**
     * Absolute disk path for an API path (`/a/b.txt` or `a/b.txt`)
     */
    protected function diskPath(string $path): string
    {
        $relative = trim($path, '/');
        return $relative === '' ? $this->fsRoot : $this->fsRoot . '/' . $relative;
    }

    /**
     * Writes a file directly on disk (parent dirs are created), without hashmap row
     */
    protected function seedFile(string $path, string $content = ''): void
    {
        $diskPath = $this->diskPath($path);
        if (!is_dir(dirname($diskPath))) {
            mkdir(dirname($diskPath), 0755, true);
        }
        file_put_contents($diskPath, $content);
    }

    /**
     * Creates a directory directly on disk, without hashmap row
     */
    protected function seedDir(string $path): void
    {
        $diskPath = $this->diskPath($path);
        if (!is_dir($diskPath)) {
            mkdir($diskPath, 0755, true);
        }
    }

    protected function readFromDisk(string $path): string
    {
        $this->assertFileOnDisk($path);
        return (string)file_get_contents($this->diskPath($path));
    }

    protected function assertFileOnDisk(string $path, ?string $expectedContent = null): void
    {
        $diskPath = $this->diskPath($path);
        $this->assertTrue(is_file($diskPath), "Expected file on disk: $path");
        if ($expectedContent !== null) {
            $this->assertSame($expectedContent, file_get_contents($diskPath), "Content of $path");
        }
    }

    protected function assertDirOnDisk(string $path): void
    {
        $this->assertTrue(is_dir($this->diskPath($path)), "Expected directory on disk: $path");
    }

    protected function assertNotOnDisk(string $path): void
    {
        $this->assertFalse(file_exists($this->diskPath($path)), "Expected nothing on disk at: $path");
    }

    // ------------------------------------------------------------------
    // hashmap (database)
    // ------------------------------------------------------------------

    /**
     * Normalizes an API path to the hashmap form (`/a/b.txt`)
     */
    protected function hashmapPath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /**
     * Inserts a hashmap row directly (no model, no access checks).
     * Access columns default to the module's `defaultPermissions` (as the self-heal repair would set them);
     * pass e.g. ['access_owner' => '5', 'access_read' => 'Editors'] to override, `null` values are kept.
     *
     * @param string $type `file` or `dir`
     */
    protected function seedHashmapRow(string $path, string $type, array $access = [], ?int $size = null): void
    {
        $defaults = array_merge($this->moduleConfig(), $this->_moduleOverrides)['defaultPermissions']
            ?? (new \ReflectionClass(Module::class))->getDefaultProperties()['defaultPermissions'];
        $row = array_merge([
            Module::ACCESS_OWNER => $defaults[Module::ACCESS_OWNER] ?? null,
            Module::ACCESS_READ => $defaults[Module::ACCESS_READ] ?? null,
            Module::ACCESS_UPDATE => $defaults[Module::ACCESS_UPDATE] ?? null,
            Module::ACCESS_DELETE => $defaults[Module::ACCESS_DELETE] ?? null,
        ], $access);
        foreach ($row as $column => $value) {
            $row[$column] = $value === null ? null : (string)$value;
        }
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('filefly_hashmap', $row + [
            'component' => self::FS_COMPONENT,
            'type' => $type,
            'path' => $this->hashmapPath($path),
            'size' => $size,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Like seedHashmapRow() with the four access columns as positional arguments (`null` is kept as NULL)
     */
    protected function seedRow(string $path, string $type, ?string $owner, ?string $read, ?string $update, ?string $delete, ?int $size = null): void
    {
        $this->seedHashmapRow($path, $type, [
            'access_owner' => $owner,
            'access_read' => $read,
            'access_update' => $update,
            'access_delete' => $delete,
        ], $size);
    }

    /**
     * Hashmap row for a path of the test filesystem, `null` if none.
     * All non-null values are strings (as MySQL returns them).
     */
    protected function hashmapRow(string $path): ?array
    {
        $row = (new Query())
            ->from('filefly_hashmap')
            ->where(['component' => self::FS_COMPONENT, 'path' => $this->hashmapPath($path)])
            ->one(Yii::$app->db);
        return $row === false ? null : $this->stringifyRow($row);
    }

    /**
     * All hashmap rows of the test filesystem ordered by path, optionally only `path LIKE $prefix%`
     *
     * @return array[]
     */
    protected function hashmapRows(?string $prefix = null): array
    {
        $query = (new Query())
            ->from('filefly_hashmap')
            ->where(['component' => self::FS_COMPONENT])
            ->orderBy(['path' => SORT_ASC]);
        if ($prefix !== null) {
            $query->andWhere(['like', 'path', $this->hashmapPath($prefix) . '%', false]);
        }
        return array_map([$this, 'stringifyRow'], $query->all(Yii::$app->db));
    }

    /**
     * Asserts a row exists and its columns match $expected (subset, string comparison)
     */
    protected function assertHashmapRow(string $path, array $expected = []): void
    {
        $row = $this->hashmapRow($path);
        $this->assertNotNull($row, "Expected hashmap row for $path");
        foreach ($expected as $column => $value) {
            $this->assertArrayHasKey($column, $row);
            $this->assertSame($value === null ? null : (string)$value, $row[$column], "hashmap.$column of $path");
        }
    }

    protected function assertNoHashmapRow(string $path): void
    {
        $this->assertNull($this->hashmapRow($path), "Expected no hashmap row for $path");
    }

    /**
     * Full-row assertion of type and all access columns (strict, `null` means NULL)
     */
    protected function assertAccess(string $path, ?string $type, ?string $owner, ?string $read, ?string $update, ?string $delete): void
    {
        $row = $this->hashmapRow($path);
        $this->assertNotNull($row, "Expected hashmap row for $path, rows: " . implode(', ', $this->rowPaths()));
        $this->assertSame(
            ['type' => $type, 'access_owner' => $owner, 'access_read' => $read, 'access_update' => $update, 'access_delete' => $delete],
            array_intersect_key($row, array_flip(['type', 'access_owner', 'access_read', 'access_update', 'access_delete'])),
            "hashmap row of $path"
        );
    }

    /**
     * Paths of all hashmap rows of the test filesystem, ordered by path
     *
     * @return string[]
     */
    protected function rowPaths(): array
    {
        return array_column($this->hashmapRows(), 'path');
    }

    private function stringifyRow(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = $value === null ? null : (string)$value;
        }
        return $row;
    }

    // ------------------------------------------------------------------
    // seeding through the API (as the acting user)
    // ------------------------------------------------------------------

    /**
     * Uploads one file through the API and asserts success. Note: with `slugNames` the stored name may differ.
     */
    protected function seedFileViaApi(string $path, string $content = ''): ApiResponse
    {
        $dir = dirname($this->hashmapPath($path));
        $response = $this->apiUpload($dir, [basename($path) => $content]);
        $this->assertApiSuccess($response);
        return $response;
    }

    /**
     * Creates a folder through the API and asserts success
     */
    protected function seedDirViaApi(string $path): ApiResponse
    {
        $response = $this->apiPost('createFolder', ['newPath' => $this->hashmapPath($path)]);
        $this->assertApiSuccess($response);
        return $response;
    }

    // ------------------------------------------------------------------
    // assertions
    // ------------------------------------------------------------------

    protected function assertStatus(int $expected, ApiResponse $response): void
    {
        $this->assertSame($expected, $response->statusCode, $response->describe());
    }

    /**
     * Asserts HTTP 200 and `{"result": {"success": true, ...}}`
     */
    protected function assertApiSuccess(ApiResponse $response): void
    {
        $this->assertStatus(200, $response);
        $json = $response->json();
        $this->assertTrue($json['result']['success'] ?? null, $response->describe());
    }

    /**
     * Asserts HTTP 200 and the exact result of a mutating action: `{"result": {"success": ..., "error": ...}}`
     */
    protected function assertResult(bool $success, string $error, ApiResponse $response): void
    {
        $this->assertStatus(200, $response);
        $this->assertSame(['result' => ['success' => $success, 'error' => $error]], $response->json());
    }

    /**
     * Asserts HTTP 200 and returns the sorted `name`s of a `list` response
     *
     * @return string[]
     */
    protected function listedNames(ApiResponse $response): array
    {
        $this->assertStatus(200, $response);
        $names = array_column($response->json()['result'], 'name');
        sort($names);
        return $names;
    }

    /**
     * Records [name, filename, errorMessage] of the given ApiController events into $log
     * (class-level handlers, removed in tearDown())
     *
     * @param string[] $names event names (FileEvent::EVENT_*)
     */
    protected function recordEvents(array $names, array &$log): void
    {
        foreach ($names as $name) {
            Event::on(ApiController::class, $name, static function (FileEvent $event) use (&$log, $name) {
                $log[] = [$name, $event->filename, $event->errorMessage];
            });
        }
    }

    /**
     * Expected shape of one `search` hit for a file path (with leading slash), with empty thumbnail and mime
     */
    protected function searchHit(string $path): array
    {
        return [
            'path' => $path,
            'type' => 'file',
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'id' => $path,
            'thumbnail' => '',
            'mime' => '',
        ];
    }

    // ------------------------------------------------------------------
    // temp directory handling
    // ------------------------------------------------------------------

    private function createWorkDir(): string
    {
        $base = realpath(sys_get_temp_dir());
        do {
            $dir = $base . '/filefly-api-' . bin2hex(random_bytes(6));
        } while (file_exists($dir));
        mkdir($dir, 0755);
        return $dir;
    }

    private function removeWorkDir(): void
    {
        $base = realpath(sys_get_temp_dir());
        if ($this->workDir === null || strpos($this->workDir, $base . '/filefly-api-') !== 0 || !is_dir($this->workDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->workDir);
    }
}
