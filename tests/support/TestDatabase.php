<?php
// file generated with AI assistance: Claude Code - [2026-11-12 18:30:00 UTC]

namespace hrzg\filefly\tests\support;

use Yii;
use yii\console\Application as ConsoleApplication;
use yii\console\controllers\MigrateController;
use yii\console\ExitCode;
use yii\db\Connection;
use yii\di\Container;

/**
 * The real MigrateController with its output written to PHP's output buffer instead of the STDOUT
 * and STDERR streams, so that TestDatabase can capture it together with what the migrations echo.
 *
 * @internal
 */
class CapturingMigrateController extends MigrateController
{
    public function stdout($string)
    {
        echo $string;
        return strlen($string);
    }

    public function stderr($string)
    {
        echo $string;
        return strlen($string);
    }
}

/**
 * Database of the API harness (ApiTestCase).
 *
 * Default: sqlite in memory with the hand-built schema of ApiTestCase (the migrations do not run
 * on sqlite). With the environment variable FILEFLY_TEST_DB_DSN set, the harness uses that real
 * database instead (MySQL/MariaDB or PostgreSQL):
 *
 * - once per PHPUnit run (tests/bootstrap.php, parent process only) the table `filefly_hashmap`
 *   and the migration history are dropped and the module's migrations are applied with Yii's
 *   `migrate/up`, as an application would do it
 * - every test then starts by emptying `filefly_hashmap` (resetTable())
 *
 * Environment variables:
 * - FILEFLY_TEST_DB_DSN: PDO DSN, e.g. `mysql:host=127.0.0.1;port=32768;dbname=filefly`
 * - FILEFLY_TEST_DB_USER, FILEFLY_TEST_DB_PASSWORD
 * - FILEFLY_TEST_DB_CHARSET: connection charset (default `utf8mb4` for MySQL, `utf8` otherwise)
 * - FILEFLY_TEST_DB_INIT_SQL: optional statement run on every new connection,
 *   e.g. `SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')`
 *
 * The database named in the DSN must be a throwaway database: the run drops tables in it.
 */
final class TestDatabase
{
    public const ENV_DSN = 'FILEFLY_TEST_DB_DSN';
    public const ENV_USER = 'FILEFLY_TEST_DB_USER';
    public const ENV_PASSWORD = 'FILEFLY_TEST_DB_PASSWORD';
    public const ENV_CHARSET = 'FILEFLY_TEST_DB_CHARSET';
    public const ENV_INIT_SQL = 'FILEFLY_TEST_DB_INIT_SQL';

    /** set by prepareForRun() in the parent process, inherited by the isolated test processes */
    public const ENV_PREPARED = 'FILEFLY_TEST_DB_PREPARED';

    public const TABLE = 'filefly_hashmap';

    /** migration history table, Yii's default name as in an application */
    public const MIGRATION_TABLE = 'migration';

    /**
     * Whether a real database is selected (FILEFLY_TEST_DB_DSN is set and not empty)
     */
    public static function isReal(): bool
    {
        return self::env(self::ENV_DSN) !== null;
    }

    /**
     * Driver name of the selected database (`sqlite`, `mysql`, `pgsql`)
     */
    public static function driverName(): string
    {
        $dsn = self::env(self::ENV_DSN) ?? 'sqlite::memory:';
        return strtolower(substr($dsn, 0, (int)strpos($dsn, ':')));
    }

    /**
     * Configuration of the `db` component
     */
    public static function connectionConfig(): array
    {
        if (!self::isReal()) {
            return self::sqliteConfig();
        }
        $config = [
            'class' => Connection::class,
            'dsn' => self::env(self::ENV_DSN),
            'username' => self::env(self::ENV_USER),
            'password' => self::env(self::ENV_PASSWORD),
            'charset' => self::env(self::ENV_CHARSET) ?? (self::driverName() === 'mysql' ? 'utf8mb4' : 'utf8'),
        ];
        $initSql = self::env(self::ENV_INIT_SQL);
        if ($initSql !== null) {
            $config['on afterOpen'] = static function ($event) use ($initSql) {
                $event->sender->pdo->exec($initSql);
            };
        }
        return $config;
    }

    /**
     * Prepares the real database once per PHPUnit run: drops `filefly_hashmap` and the migration
     * history and applies all module migrations with `migrate/up`. Does nothing for sqlite and in
     * the isolated test processes (they inherit FILEFLY_TEST_DB_PREPARED from the parent).
     *
     * @throws \RuntimeException if a migration fails, with the migration output
     */
    public static function prepareForRun(): void
    {
        if (!self::isReal() || self::env(self::ENV_PREPARED) !== null) {
            return;
        }
        $start = microtime(true);
        $output = self::migrateFresh();
        self::markPrepared();
        fwrite(STDERR, sprintf(
            "filefly test db: %s, migrations applied in %.2f s\n%s",
            self::describe(),
            microtime(true) - $start,
            getenv('FILEFLY_TEST_DB_VERBOSE') ? $output : ''
        ));
    }

    /**
     * Drops `filefly_hashmap` and the migration history, then runs `migrate/up` for the module's
     * migrations in a console application (authManager: a PhpManager in a temp directory, as the
     * RBAC migrations need one). Returns the migration output.
     *
     * @throws \RuntimeException if `migrate/up` fails
     */
    public static function migrateFresh(): string
    {
        return self::withConsoleApplication(static function (ConsoleApplication $app) {
            $db = $app->db;
            foreach ([self::TABLE, self::MIGRATION_TABLE] as $table) {
                if ($db->getTableSchema($table, true) !== null) {
                    $db->createCommand()->dropTable($table)->execute();
                }
            }
            $db->getSchema()->refresh();
            [$exitCode, $output] = self::runMigrateAction($app, 'up', []);
            if ($exitCode !== ExitCode::OK) {
                throw new \RuntimeException(
                    "migrate/up failed with exit code $exitCode on " . self::env(self::ENV_DSN) . ":\n" . $output
                );
            }
            return $output;
        });
    }

    /**
     * Runs one `migrate/<action>` (e.g. `up`, `down`) with the given arguments against the
     * selected database and returns exit code and output; for probes, not used by the tests.
     *
     * @return array [int exit code, string output]
     */
    public static function migrate(string $action, array $args = []): array
    {
        return self::withConsoleApplication(static function (ConsoleApplication $app) use ($action, $args) {
            return self::runMigrateAction($app, $action, $args);
        });
    }

    /**
     * Empties `filefly_hashmap` at the start of a test. DELETE instead of TRUNCATE: on the
     * test-sized table it is the faster of the two on MariaDB and PostgreSQL (TRUNCATE recreates
     * the table), and ids are never asserted.
     */
    public static function resetTable(Connection $db): void
    {
        $db->createCommand()->delete(self::TABLE)->execute();
    }

    /**
     * Short description of the selected database for the run output, without credentials
     */
    public static function describe(): string
    {
        if (!self::isReal()) {
            return 'sqlite in memory';
        }
        $dsn = (string)self::env(self::ENV_DSN);
        /** @var Connection $db */
        $db = Yii::createObject(self::connectionConfig());
        try {
            $version = $db->getServerVersion();
        } finally {
            $db->close();
        }
        return $dsn . ' (' . $version . ')';
    }

    /**
     * sqlite in-memory database. MySQL's NOW(), used by FileflyHashmap's TimestampBehavior,
     * is registered as a user function.
     */
    private static function sqliteConfig(): array
    {
        $config = [
            'class' => Connection::class,
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
     * @return array [int exit code, string output]
     */
    private static function runMigrateAction(ConsoleApplication $app, string $action, array $args): array
    {
        // yii\db\Migration echoes its progress, CapturingMigrateController the rest
        ob_start();
        try {
            $exitCode = $app->runAction('migrate/' . $action, array_merge($args, ['interactive' => 0, 'color' => 0]));
        } finally {
            $output = ob_get_clean();
        }
        return [(int)$exitCode, $output];
    }

    /**
     * Runs $callback with a console application that has `db`, a PhpManager `authManager` in a
     * temp directory and the controller `migrate` for the module's migrations; restores
     * Yii::$app and the DI container afterwards.
     */
    private static function withConsoleApplication(callable $callback)
    {
        $repoRoot = dirname(__DIR__, 2);
        $workDir = self::createTempDir();
        $savedApp = Yii::$app;
        $savedContainer = Yii::$container;
        try {
            $app = new ConsoleApplication([
                'id' => 'filefly-test-migrate',
                'basePath' => $workDir,
                'runtimePath' => $workDir,
                'vendorPath' => $repoRoot . '/vendor',
                'aliases' => ['@hrzg/filefly' => $repoRoot],
                'controllerMap' => [
                    'migrate' => [
                        'class' => CapturingMigrateController::class,
                        'migrationPath' => ['@hrzg/filefly/migrations'],
                        'migrationTable' => self::MIGRATION_TABLE,
                    ],
                ],
                'components' => [
                    'db' => self::connectionConfig(),
                    'authManager' => [
                        'class' => \yii\rbac\PhpManager::class,
                        'itemFile' => $workDir . '/items.php',
                        'assignmentFile' => $workDir . '/assignments.php',
                        'ruleFile' => $workDir . '/rules.php',
                    ],
                ],
            ]);
            try {
                return $callback($app);
            } finally {
                $app->db->close();
            }
        } finally {
            Yii::$app = $savedApp;
            Yii::$container = $savedContainer ?? new Container();
            self::removeTempDir($workDir);
        }
    }

    private static function markPrepared(): void
    {
        putenv(self::ENV_PREPARED . '=1');
        $_ENV[self::ENV_PREPARED] = '1';
        $_SERVER[self::ENV_PREPARED] = '1';
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? null : $value;
    }

    private static function createTempDir(): string
    {
        $base = realpath(sys_get_temp_dir());
        do {
            $dir = $base . '/filefly-migrate-' . bin2hex(random_bytes(6));
        } while (file_exists($dir));
        mkdir($dir, 0755);
        return $dir;
    }

    private static function removeTempDir(string $dir): void
    {
        $base = realpath(sys_get_temp_dir());
        if (strpos($dir, $base . '/filefly-migrate-') !== 0 || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
