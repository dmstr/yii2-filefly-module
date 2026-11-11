<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\console;

use hrzg\filefly\components\storage\ItemExistsException;
use hrzg\filefly\tests\adapters\suite\support\RawTree;
use hrzg\filefly\tests\adapters\suite\support\TestRoot;
use hrzg\filefly\tests\support\ConsoleResult;
use hrzg\filefly\tests\support\ConsoleTestCase;
use League\Flysystem\FilesystemOperator;
use Yii;

/**
 * `fs sync` through the real console controller between the local filesystem `a://` and a remote
 * one `b://` (S3 or FTP), in both directions: new files copied, an older target replaced, a newer
 * target skipped, directories skipped, and the target path rule `$dest . <item path>` that the
 * default suite pins for local.
 *
 * `a://` is a local temp directory (the disk helpers of ConsoleTestCase work on it, with pinned
 * modification times); `b://` is a fresh test root on the remote backend, seeded and inspected
 * through its own Flysystem operator. A remote file gets the time of its upload, so "older" and
 * "newer" local sources are pinned an hour before and after now.
 */
abstract class RemoteSyncTestCase extends ConsoleTestCase
{
    /**
     * @var TestRoot|null
     */
    private $remote;

    abstract protected function createRemoteRoot(): TestRoot;

    protected function setUp(): void
    {
        $this->remote = $this->createRemoteRoot();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->remote !== null) {
            $this->remote->remove();
        }
        $this->remote = null;
    }

    protected function filesystemComponentConfig(string $root): array
    {
        if ($root === $this->roots[self::SCHEME_B]) {
            return $this->remote->config;
        }
        return parent::filesystemComponentConfig($root);
    }

    private function remoteOperator(): FilesystemOperator
    {
        return Yii::$app->get(self::COMPONENTS[self::SCHEME_B])->getFilesystem();
    }

    private function remoteTree(): array
    {
        return RawTree::of($this->remoteOperator());
    }

    private function sync(string $src, string $dest, bool $recursive = true): ConsoleResult
    {
        return $this->runFs('sync', [$src, $dest], $recursive ? ['recursive' => 1] : []);
    }

    private function assertDone(ConsoleResult $result, array $tokens): void
    {
        $this->assertNull($result->exception, 'unexpected exception');
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('', $result->stderr);
        $this->assertSame("\nDone.\n", substr($result->stdout, -7), $result->stdout);
        $printed = preg_replace('/Skipped directory \'[^\']*\'\n/', '', substr($result->stdout, 0, -7));
        $printed = str_split($printed);
        sort($printed);
        sort($tokens);
        $this->assertSame($tokens, $printed, $result->stdout);
    }

    // ------------------------------------------------------------------
    // local to remote
    // ------------------------------------------------------------------

    public function testLocalToRemoteCopiesNewFilesIntoNestedDirectories(): void
    {
        $this->seedFile('a', 'x.txt', 'X', time() - 3600);
        $this->seedFile('a', 'd/y.txt', 'YY', time() - 3600);
        $this->seedFile('a', 'd/e/z.txt', 'ZZZ', time() - 3600);
        $this->seedDir('a', 'empty');

        $result = $this->sync('a://', 'b://');

        $this->assertDone($result, ['+', '+', '+']);
        $this->assertStringContainsString("Skipped directory 'empty'", $result->stdout);
        $this->assertSame(['d/' => null, 'd/e/' => null, 'd/e/z.txt' => 'ZZZ', 'd/y.txt' => 'YY', 'x.txt' => 'X'], $this->remoteTree());
        $this->assertTree('a', ['x.txt' => 'X', 'd' => self::DIR, 'd/y.txt' => 'YY', 'd/e' => self::DIR, 'd/e/z.txt' => 'ZZZ', 'empty' => self::DIR]);
    }

    public function testLocalToRemoteReplacesOlderTargetAndSkipsNewerTarget(): void
    {
        $this->remoteOperator()->write('older.txt', 'remote old');
        $this->remoteOperator()->write('newer.txt', 'remote new');
        $this->seedFile('a', 'older.txt', 'local newer source', time() + 3600);
        $this->seedFile('a', 'newer.txt', 'local older source', time() - 3600);

        $result = $this->sync('a://', 'b://');

        $this->assertDone($result, ['+', '.']);
        $this->assertSame(['newer.txt' => 'remote new', 'older.txt' => 'local newer source'], $this->remoteTree());
    }

    public function testSecondRunSkipsEverything(): void
    {
        $this->seedFile('a', 'x.txt', 'X', time() - 3600);
        $this->seedFile('a', 'd/y.txt', 'YY', time() - 3600);

        $this->assertDone($this->sync('a://', 'b://'), ['+', '+']);
        $this->assertDone($this->sync('a://', 'b://'), ['.', '.']);

        $this->assertSame(['d/' => null, 'd/y.txt' => 'YY', 'x.txt' => 'X'], $this->remoteTree());
    }

    public function testTargetPathIsTheTargetGluedToTheItemPath(): void
    {
        $this->seedFile('a', 'd/y.txt', 'YY', time() - 3600);
        $this->remoteOperator()->createDirectory('t');

        $this->assertDone($this->sync('a://d', 'b://t/'), ['+']);
        $this->assertDone($this->sync('a://d', 'b://t'), ['+']);

        // `b://t/` . `d/y.txt` and `b://t` . `d/y.txt`
        $this->assertSame(['t/' => null, 't/d/' => null, 't/d/y.txt' => 'YY', 'td/' => null, 'td/y.txt' => 'YY'], $this->remoteTree());
    }

    /**
     * A newer source file whose target is a directory: on local the directory's own (newer) time
     * makes sync skip the file; S3 and FTP have no time for a directory (lastModified() is `null`),
     * so the file counts as newer and replaceFile() refuses the directory: the sync stops with
     * ItemExistsException, the directory and its content stay
     */
    public function testNewerSourceFileOntoRemoteDirectoryIsRefusedAndStopsTheSync(): void
    {
        $this->remoteOperator()->write('x.txt/inside.txt', 'keep');
        $this->seedFile('a', 'x.txt', 'X', time() + 3600);

        $result = $this->sync('a://', 'b://');

        $this->assertSame(ItemExistsException::class, $result->exception);
        $this->assertSame(['x.txt/' => null, 'x.txt/inside.txt' => 'keep'], $this->remoteTree());
    }

    // ------------------------------------------------------------------
    // remote to local
    // ------------------------------------------------------------------

    public function testRemoteToLocalCopiesNewFilesReplacesOlderAndSkipsNewer(): void
    {
        $this->remoteOperator()->write('new.txt', 'N');
        $this->remoteOperator()->write('d/deep.txt', 'DEEP');
        $this->remoteOperator()->write('older.txt', 'remote is newer');
        $this->remoteOperator()->write('newer.txt', 'remote is older');
        $this->remoteOperator()->createDirectory('emptydir');
        $this->seedFile('a', 'older.txt', 'local old', time() - 3600);
        $this->seedFile('a', 'newer.txt', 'local new', time() + 3600);

        $result = $this->sync('b://', 'a://');

        $this->assertDone($result, ['+', '+', '+', '.']);
        $this->assertTree('a', [
            'd' => self::DIR,
            'd/deep.txt' => 'DEEP',
            'new.txt' => 'N',
            'newer.txt' => 'local new',
            'older.txt' => 'remote is newer',
        ]);
    }
}
