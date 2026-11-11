<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\support;

use hrzg\filefly\commands\FsController;
use hrzg\filefly\helpers\FsManager;
use hrzg\filefly\Module;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\di\Container;

/**
 * Captured result of one console action
 */
class ConsoleResult
{
    /** @var int|null exit code, null if the action threw */
    public $exitCode;

    /** @var string everything written with stdout() */
    public $stdout = '';

    /** @var string everything written with stderr() */
    public $stderr = '';

    /** @var string|null class name of the exception thrown by the action, null if none */
    public $exception;
}

/**
 * The real FsController with only its two output methods redirected into memory.
 * Console::stdout() writes to the STDOUT constant, which cannot be captured in-process.
 * All behaviour under test (actions, option handling, FsManager use) is inherited unchanged.
 *
 * @internal
 */
class CapturingFsController extends FsController
{
    public static $stdout = '';
    public static $stderr = '';

    public function stdout($string)
    {
        self::$stdout .= $string;
        return strlen($string);
    }

    public function stderr($string)
    {
        self::$stderr .= $string;
        return strlen($string);
    }
}

/**
 * Base test case for characterisation tests of the filefly console command (FsController).
 *
 * Per test it boots a real \yii\console\Application with the filefly module and two local
 * filesystem components rooted in two fresh temp directories. The module maps the schemes
 * `a://` and `b://` to them (`filesystemComponents`), the console config maps the controller id
 * `fs` to FsController (controllerMap), as the phd5 application does.
 *
 * Actions are run through Application::runAction() with non-interactive mode, so confirm()
 * prompts are answered with yes. Exceptions thrown by an action are caught and reported in
 * ConsoleResult::$exception.
 *
 * Unlike ApiTestCase this does not need a process per test: the console code has no
 * function-static caches, and tearDown() drops the application and the DI container.
 *
 * Public helper API (stable, see tests/README.md):
 * - run: runFs(), fsManager()
 * - disk: seedFile(), seedDir(), touchPath(), mtime(), readFromDisk(), diskPath(), tree(), hash()
 * - assertions: assertTree(), assertTreeUnchanged(), snapshot()
 */
abstract class ConsoleTestCase extends TestCase
{
    /** scheme of the first filesystem */
    public const SCHEME_A = 'a';

    /** scheme of the second filesystem */
    public const SCHEME_B = 'b';

    /** marker for directories in tree() */
    public const DIR = 'dir';

    /** @var string[] scheme => yii component id */
    protected const COMPONENTS = [self::SCHEME_A => 'fsA', self::SCHEME_B => 'fsB'];

    /** @var string base temp directory of this test */
    protected $workDir;

    /** @var string[] scheme => root directory */
    protected $roots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = $this->createWorkDir();
        foreach (self::COMPONENTS as $scheme => $component) {
            $this->roots[$scheme] = $this->workDir . '/' . $scheme;
            mkdir($this->roots[$scheme], 0755, true);
        }
        mkdir($this->workDir . '/runtime', 0755, true);

        CapturingFsController::$stdout = '';
        CapturingFsController::$stderr = '';

        new Application($this->applicationConfig());
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
        Yii::$container = new Container();
        CapturingFsController::$stdout = '';
        CapturingFsController::$stderr = '';

        $this->removeWorkDir();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // application setup (the only place with Flysystem specific config)
    // ------------------------------------------------------------------

    /**
     * Configuration of the filesystem component rooted in $root.
     * This is the single Flysystem-version specific part of the harness.
     */
    protected function filesystemComponentConfig(string $root): array
    {
        return [
            'class' => \creocoder\flysystem\LocalFilesystem::class,
            'path' => $root,
            // directories as Flysystem 1 created them (0755); creocoder's default is 0740
            'config' => ['directory_visibility' => 'public'],
        ];
    }

    protected function moduleConfig(): array
    {
        return [
            'class' => Module::class,
            'filesystemComponents' => self::COMPONENTS,
        ];
    }

    protected function applicationConfig(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $components = [];
        foreach (self::COMPONENTS as $scheme => $component) {
            $components[$component] = $this->filesystemComponentConfig($this->roots[$scheme]);
        }
        return [
            'id' => 'filefly-console-test',
            'basePath' => $this->workDir,
            'runtimePath' => $this->workDir . '/runtime',
            'vendorPath' => $repoRoot . '/vendor',
            'aliases' => ['@hrzg/filefly' => $repoRoot],
            'modules' => ['filefly' => $this->moduleConfig()],
            'controllerMap' => ['fs' => ['class' => CapturingFsController::class]],
            'components' => $components,
        ];
    }

    // ------------------------------------------------------------------
    // running actions
    // ------------------------------------------------------------------

    /**
     * Runs `fs/<action>` with positional arguments and the given options (e.g. ['recursive' => 1])
     */
    protected function runFs(string $action, array $args = [], array $options = []): ConsoleResult
    {
        CapturingFsController::$stdout = '';
        CapturingFsController::$stderr = '';

        $result = new ConsoleResult();
        try {
            $result->exitCode = Yii::$app->runAction('fs/' . $action, array_merge($args, $options, ['interactive' => 0, 'color' => 0]));
        } catch (\Throwable $e) {
            $result->exception = get_class($e);
        }
        $result->stdout = CapturingFsController::$stdout;
        $result->stderr = CapturingFsController::$stderr;
        return $result;
    }

    /**
     * A fresh FsManager bound to the module, to call FsManager::sync() directly
     */
    protected function fsManager(): FsManager
    {
        $manager = new FsManager();
        $manager->setModule(Yii::$app->getModule('filefly'));
        return $manager;
    }

    // ------------------------------------------------------------------
    // disk helpers, paths are relative to the root of the filesystem of $scheme
    // ------------------------------------------------------------------

    protected function diskPath(string $scheme, string $path = ''): string
    {
        return $this->roots[$scheme] . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * Creates a file (and its parent directories); $mtime pins the modification time
     */
    protected function seedFile(string $scheme, string $path, string $content = '', ?int $mtime = null): void
    {
        $file = $this->diskPath($scheme, $path);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
        chmod($file, 0644);
        if ($mtime !== null) {
            touch($file, $mtime);
        }
    }

    protected function seedDir(string $scheme, string $path): void
    {
        mkdir($this->diskPath($scheme, $path), 0755, true);
    }

    protected function touchPath(string $scheme, string $path, int $mtime): void
    {
        touch($this->diskPath($scheme, $path), $mtime);
    }

    protected function mtime(string $scheme, string $path): int
    {
        clearstatcache();
        return filemtime($this->diskPath($scheme, $path));
    }

    protected function readFromDisk(string $scheme, string $path): string
    {
        return file_get_contents($this->diskPath($scheme, $path));
    }

    protected static function hash(string $content): string
    {
        return sha1($content);
    }

    /**
     * Complete tree of one root as sorted map `relative path => sha1 of content` (directories: DIR).
     * Paths have no leading slash.
     */
    protected function tree(string $scheme): array
    {
        $root = $this->roots[$scheme];
        $tree = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($root) + 1);
            $tree[$relative] = $item->isDir() ? self::DIR : sha1_file($item->getPathname());
        }
        ksort($tree);
        return $tree;
    }

    /**
     * Expected trees are written with file contents, not hashes: ['f.txt' => 'content', 'd' => self::DIR]
     */
    protected function assertTree(string $scheme, array $expectedContents, string $message = ''): void
    {
        $expected = [];
        foreach ($expectedContents as $path => $content) {
            $expected[$path] = $content === self::DIR ? self::DIR : self::hash($content);
        }
        ksort($expected);
        $this->assertSame($expected, $this->tree($scheme), $message ?: "tree of root '$scheme'");
    }

    /**
     * Snapshot of both roots, to be compared with assertTreeUnchanged()
     */
    protected function snapshot(): array
    {
        return [self::SCHEME_A => $this->tree(self::SCHEME_A), self::SCHEME_B => $this->tree(self::SCHEME_B)];
    }

    protected function assertTreeUnchanged(array $snapshot): void
    {
        $this->assertSame($snapshot, $this->snapshot(), 'both roots unchanged');
    }

    // ------------------------------------------------------------------
    // temp directory handling
    // ------------------------------------------------------------------

    private function createWorkDir(): string
    {
        $base = realpath(sys_get_temp_dir());
        do {
            $dir = $base . '/filefly-console-' . bin2hex(random_bytes(6));
        } while (file_exists($dir));
        mkdir($dir, 0755);
        return $dir;
    }

    private function removeWorkDir(): void
    {
        $base = realpath(sys_get_temp_dir());
        if ($this->workDir === null || strpos($this->workDir, $base . '/filefly-console-') !== 0 || !is_dir($this->workDir)) {
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
