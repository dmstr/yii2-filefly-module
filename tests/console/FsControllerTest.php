<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\console;

use hrzg\filefly\tests\support\ConsoleResult;
use hrzg\filefly\tests\support\ConsoleTestCase;

/**
 * Characterisation tests of the console command `fs` (hrzg\filefly\commands\FsController) and of
 * hrzg\filefly\helpers\FsManager::sync(): they pin what the code does today, also where it looks unintended.
 *
 * Two local filesystems are mounted as `a://` and `b://`, both start empty. File contents are compared as
 * complete trees (relative path => hash, see ConsoleTestCase::tree()). Mtimes are set with touch(), never with sleep().
 *
 * Exceptions: the Flysystem 1 exception class is not part of the contract, tests assert only that the action
 * threw (exit code null, `exception` set) and what is left on disk.
 */
class FsControllerTest extends ConsoleTestCase
{
    private const OLD = 1000;
    private const MID = 5000;
    private const NEW = 9000;

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function assertThrew(ConsoleResult $result): void
    {
        $this->assertNull($result->exitCode, 'the action must throw, not return an exit code');
        $this->assertNotNull($result->exception);
    }

    /**
     * The command failed, the two outcomes are told apart by the result itself: on macOS the stream copy of a
     * directory raises a PHP notice that the harness turns into an exception (no exit code, no output), on Linux
     * no notice is raised and the action returns exit code 1 with $stderr.
     */
    private function assertFailedOrThrew(ConsoleResult $result, string $stderr): void
    {
        if ($result->exception !== null) {
            $this->assertNull($result->exitCode, 'threw and returned an exit code');
            $this->assertSame('', $result->stdout);
            $this->assertSame('', $result->stderr);
            return;
        }
        $this->assertFailed($result, $stderr);
    }

    private function assertOk(ConsoleResult $result, string $stdout = '', string $stderr = ''): void
    {
        $this->assertNull($result->exception, 'unexpected exception');
        $this->assertSame(0, $result->exitCode);
        $this->assertSame($stdout, $result->stdout);
        $this->assertSame($stderr, $result->stderr);
    }

    private function assertFailed(ConsoleResult $result, string $stderr): void
    {
        $this->assertNull($result->exception, 'unexpected exception');
        $this->assertSame(1, $result->exitCode);
        $this->assertSame('', $result->stdout);
        $this->assertSame($stderr, $result->stderr);
    }

    /**
     * Short listing: every entry is the path padded to 10 characters followed by a tab, one final newline.
     * The order of entries is the order of the adapter, so entries are compared sorted.
     */
    private function assertShortListing(array $paths, string $stdout): void
    {
        if ($paths === []) {
            $this->assertSame("\n", $stdout);
            return;
        }
        $this->assertSame("\t\n", substr($stdout, -2), 'listing ends with tab and newline');
        $entries = explode("\t", substr($stdout, 0, -2));
        sort($entries);
        $expected = array_map(static function ($path) {
            return str_pad($path, 10, ' ');
        }, $paths);
        sort($expected);
        $this->assertSame($expected, $entries);
    }

    /**
     * Long listing: one line `<type char>  <size padded to 10>\t<timestamp padded to 10>\t<path>` per entry
     * (size `n/a` for directories) and one final newline. The timestamp of directories depends on when the
     * directory was created and is replaced by `TS`; the order of lines is the order of the adapter.
     */
    private function assertLongListing(array $expectedLines, string $stdout): void
    {
        if ($expectedLines === []) {
            $this->assertSame("\n", $stdout);
            return;
        }
        $this->assertSame("\n\n", substr($stdout, -2), 'listing ends with an empty line');
        $lines = explode("\n", substr($stdout, 0, -2));
        $lines = array_map(static function ($line) {
            return preg_replace('/^(d  n\/a       \t)\d+( *\t)/', '$1TS$2', $line);
        }, $lines);
        sort($lines);
        sort($expectedLines);
        $this->assertSame($expectedLines, $lines);
    }

    private static function fileLine(int $size, int $mtime, string $path): string
    {
        return sprintf("f  %s\t%s\t%s", str_pad((string)$size, 10, ' '), str_pad((string)$mtime, 10, ' '), $path);
    }

    private static function dirLine(string $path): string
    {
        return sprintf("d  n/a       \tTS\t%s", $path);
    }

    /**
     * Runs a sync either through the console action or through FsManager::sync(), as the controller does.
     * Through FsManager there is no output, the result only carries the exception (exit code 0 if none).
     */
    private function sync(string $via, string $src, string $dest, bool $recursive = false): ConsoleResult
    {
        if ($via === 'console') {
            return $this->runFs('sync', [$src, $dest], $recursive ? ['recursive' => 1] : []);
        }
        $result = new ConsoleResult();
        try {
            $this->fsManager()->sync($src, $dest, $recursive);
            $result->exitCode = 0;
        } catch (\Throwable $e) {
            $result->exception = get_class($e);
        }
        return $result;
    }

    public function syncViaProvider(): array
    {
        return ['console action' => ['console'], 'FsManager::sync()' => ['manager']];
    }

    /** deterministic pseudo random content of the given size */
    private static function blob(int $bytes): string
    {
        $data = '';
        for ($i = 0; strlen($data) < $bytes; $i++) {
            $data .= hash('sha256', 'blob' . $i, true);
        }
        return substr($data, 0, $bytes);
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function testIndexListsConfiguredSchemesWithTheirComponentNames(): void
    {
        $result = $this->runFs('index');

        $this->assertOk($result, "FlyCLI\n\na://      \tfsA\nb://      \tfsB\n");
    }

    // ------------------------------------------------------------------
    // ls
    // ------------------------------------------------------------------

    public function testLsOfEmptyRootPrintsOnlyANewline(): void
    {
        $result = $this->runFs('ls', ['a://']);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("\n", $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testLsShortListsFilesAndDirectoriesOfRootNonRecursively(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');
        $this->seedFile('a', 'd/y.txt', 'yy');
        $this->seedDir('a', 'empty');

        $result = $this->runFs('ls', ['a://']);

        $this->assertSame(0, $result->exitCode);
        $this->assertShortListing(['d', 'empty', 'x.txt'], $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testLsShortRecursiveListsNestedEntriesWithPathsRelativeToFilesystemRoot(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');
        $this->seedFile('a', 'd/y.txt', 'yy');
        $this->seedFile('a', 'd/e/z.txt', 'z');

        $result = $this->runFs('ls', ['a://'], ['recursive' => 1]);

        $this->assertSame(0, $result->exitCode);
        $this->assertShortListing(['d', 'd/e', 'd/e/z.txt', 'd/y.txt', 'x.txt'], $result->stdout);
    }

    public function testLsOfSubdirectoryPrintsPathsWithTheDirectoryPrefix(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');
        $this->seedFile('a', 'd/y.txt', 'yy');
        $this->seedFile('a', 'd/e/z.txt', 'z');

        $result = $this->runFs('ls', ['a://d']);

        $this->assertSame(0, $result->exitCode);
        $this->assertShortListing(['d/e', 'd/y.txt'], $result->stdout);
    }

    public function testLsOfSubdirectoryRecursive(): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy');
        $this->seedFile('a', 'd/e/z.txt', 'z');
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('ls', ['a://d'], ['recursive' => 1]);

        $this->assertSame(0, $result->exitCode);
        $this->assertShortListing(['d/e', 'd/e/z.txt', 'd/y.txt'], $result->stdout);
    }

    public function testLsTrailingSlashOnDirectoryGivesSameListing(): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy');

        $withSlash = $this->runFs('ls', ['a://d/']);
        $withoutSlash = $this->runFs('ls', ['a://d']);

        $this->assertSame(0, $withSlash->exitCode);
        $this->assertSame($withoutSlash->stdout, $withSlash->stdout);
        $this->assertShortListing(['d/y.txt'], $withSlash->stdout);
    }

    public function testLsOfRootWithSeveralSlashesListsRoot(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('ls', ['a:///']);

        $this->assertSame(0, $result->exitCode);
        $this->assertShortListing(['x.txt'], $result->stdout);
    }

    public function testLsLongPrintsTypeSizeTimestampAndPath(): void
    {
        $this->seedFile('a', 'x.txt', 'hi', self::OLD);
        $this->seedFile('a', 'empty.txt', '', self::MID);
        $this->seedDir('a', 'dir');

        $result = $this->runFs('ls', ['a://'], ['long' => 1]);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('', $result->stderr);
        $this->assertLongListing([
            self::fileLine(2, self::OLD, 'x.txt'),
            self::fileLine(0, self::MID, 'empty.txt'),
            self::dirLine('dir'),
        ], $result->stdout);
    }

    public function testLsLongRecursiveListsNestedEntries(): void
    {
        $this->seedFile('a', 'd/e/z.txt', 'z', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'yy', self::MID);

        $result = $this->runFs('ls', ['a://'], ['long' => 1, 'recursive' => 1]);

        $this->assertSame(0, $result->exitCode);
        $this->assertLongListing([
            self::dirLine('d'),
            self::dirLine('d/e'),
            self::fileLine(1, self::OLD, 'd/e/z.txt'),
            self::fileLine(2, self::MID, 'd/y.txt'),
        ], $result->stdout);
    }

    public function testLsLongOfEmptyRootPrintsOnlyANewline(): void
    {
        $result = $this->runFs('ls', ['a://'], ['long' => 1]);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("\n", $result->stdout);
    }

    public function testLsOfMissingPathPrintsEmptyListingAndSucceeds(): void
    {
        // NOTE: current behaviour, looks unintended: a missing path is not an error, exit code 0, empty output
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('ls', ['a://nope']);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("\n", $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testLsOfFilePathPrintsEmptyListingAndSucceeds(): void
    {
        // NOTE: current behaviour, looks unintended: listing a file prints nothing and does not name the file
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('ls', ['a://x.txt']);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("\n", $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testLsListsOnlyTheRequestedFilesystem(): void
    {
        $this->seedFile('a', 'in-a.txt', 'a');
        $this->seedFile('b', 'in-b.txt', 'b');

        $result = $this->runFs('ls', ['b://']);

        $this->assertShortListing(['in-b.txt'], $result->stdout);
    }

    public function testLsOfUnknownSchemeThrows(): void
    {
        // NOTE: current behaviour, looks unintended: no error message, the undefined array key of
        // filesystemComponents[...] surfaces as a PHP warning (converted to an exception by the error handler)
        $result = $this->runFs('ls', ['zz://']);

        $this->assertThrew($result);
        $this->assertSame('', $result->stdout);
    }

    public function testLsWithoutSchemeSeparatorThrows(): void
    {
        // NOTE: current behaviour, looks unintended: `a` is mounted as scheme but the path has no `a://` prefix
        $result = $this->runFs('ls', ['a']);

        $this->assertThrew($result);
    }

    public function testLsDoesNotChangeAnything(): void
    {
        $this->seedFile('a', 'x.txt', 'hi', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);
        $before = $this->snapshot();

        $this->runFs('ls', ['a://'], ['recursive' => 1, 'long' => 1]);

        $this->assertTreeUnchanged($before);
        $this->assertSame(self::OLD, $this->mtime('a', 'x.txt'));
    }

    // ------------------------------------------------------------------
    // mkdir
    // ------------------------------------------------------------------

    public function testMkdirCreatesDirectory(): void
    {
        $result = $this->runFs('mkdir', ['a://new']);

        $this->assertOk($result);
        $this->assertTree('a', ['new' => self::DIR]);
        $this->assertTree('b', []);
    }

    public function testMkdirCreatesMissingParentDirectories(): void
    {
        $result = $this->runFs('mkdir', ['a://p/q/r']);

        $this->assertOk($result);
        $this->assertTree('a', ['p' => self::DIR, 'p/q' => self::DIR, 'p/q/r' => self::DIR]);
    }

    public function testMkdirInSecondFilesystemLeavesFirstUntouched(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('mkdir', ['b://new']);

        $this->assertOk($result);
        $this->assertTree('a', ['x.txt' => 'hi']);
        $this->assertTree('b', ['new' => self::DIR]);
    }

    public function testMkdirOfExistingDirectorySucceedsAndKeepsItsContent(): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy');

        $result = $this->runFs('mkdir', ['a://d']);

        $this->assertOk($result);
        $this->assertTree('a', ['d' => self::DIR, 'd/y.txt' => 'yy']);
    }

    public function testMkdirOverExistingFileFailsWithExitCodeOne(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('mkdir', ['a://x.txt']);

        $this->assertFailed($result, 'Creation of directory failed');
        $this->assertTree('a', ['x.txt' => 'hi']);
    }

    public function testMkdirBelowExistingFileFailsWithExitCodeOne(): void
    {
        $this->seedFile('a', 'x.txt', 'hi');

        $result = $this->runFs('mkdir', ['a://x.txt/sub']);

        $this->assertFailed($result, 'Creation of directory failed');
        $this->assertTree('a', ['x.txt' => 'hi']);
    }

    public function testMkdirOfFilesystemRootSucceedsAndChangesNothing(): void
    {
        $result = $this->runFs('mkdir', ['b://']);

        $this->assertOk($result);
        $this->assertTree('b', []);
    }

    public function testMkdirWithUnknownSchemeThrows(): void
    {
        $before = $this->snapshot();

        $result = $this->runFs('mkdir', ['zz://new']);

        $this->assertThrew($result);
        $this->assertTreeUnchanged($before);
    }

    // ------------------------------------------------------------------
    // cp
    // ------------------------------------------------------------------

    public function testCpCopiesFileAcrossFilesystems(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('cp', ['a://x.txt', 'b://x.txt']);

        $this->assertOk($result);
        $this->assertTree('a', ['x.txt' => 'hello']);
        $this->assertTree('b', ['x.txt' => 'hello']);
    }

    public function testCpCopiesFileWithinOneFilesystem(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('cp', ['a://x.txt', 'a://copy.txt']);

        $this->assertOk($result);
        $this->assertTree('a', ['x.txt' => 'hello', 'copy.txt' => 'hello']);
        $this->assertTree('b', []);
    }

    public function testCpCreatesMissingParentDirectoriesOfTarget(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('cp', ['a://x.txt', 'b://sub/deeper/x.txt']);

        $this->assertOk($result);
        $this->assertTree('b', ['sub' => self::DIR, 'sub/deeper' => self::DIR, 'sub/deeper/x.txt' => 'hello']);
    }

    public function testCpCopiesEmptyFile(): void
    {
        $this->seedFile('a', 'empty.txt', '');

        $result = $this->runFs('cp', ['a://empty.txt', 'b://empty.txt']);

        $this->assertOk($result);
        $this->assertTree('b', ['empty.txt' => '']);
    }

    public function testCpSetsMtimeOfTargetToNowInsteadOfKeepingTheSourceMtime(): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $this->runFs('cp', ['a://x.txt', 'b://x.txt']);

        // NOTE: current behaviour: the mtime is not preserved, the copy looks newer than its source
        $this->assertGreaterThan(self::NEW, $this->mtime('b', 'x.txt'));
        $this->assertSame(self::OLD, $this->mtime('a', 'x.txt'));
    }

    public function testCpOntoExistingFileThrowsAndKeepsTarget(): void
    {
        $this->seedFile('a', 'x.txt', 'new');
        $this->seedFile('b', 'x.txt', 'old');

        $result = $this->runFs('cp', ['a://x.txt', 'b://x.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', ['x.txt' => 'new']);
        $this->assertTree('b', ['x.txt' => 'old']);
    }

    public function testCpOntoItselfThrowsAndKeepsFile(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('cp', ['a://x.txt', 'a://x.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', ['x.txt' => 'hello']);
    }

    public function testCpOntoExistingDirectoryThrowsAndKeepsEverything(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');
        $this->seedFile('b', 'd/y.txt', 'yy');

        $result = $this->runFs('cp', ['a://x.txt', 'b://d']);

        $this->assertThrew($result);
        $this->assertTree('b', ['d' => self::DIR, 'd/y.txt' => 'yy']);
    }

    public function testCpOfMissingSourceThrowsAndCreatesNothing(): void
    {
        $result = $this->runFs('cp', ['a://nope.txt', 'b://n.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', []);
        $this->assertTree('b', []);
    }

    public function testCpOfDirectoryFailsAndLeavesAnEmptyFileAtTheTarget(): void
    {
        // NOTE: current behaviour, looks unintended: directories cannot be copied, but before the error
        // an empty FILE named like the directory is created at the target. The failure differs by OS:
        // macOS throws (PHP notice turned into an exception), Linux returns exit code 1 with `Copy failed`
        $this->seedFile('a', 'd/y.txt', 'yy');

        $result = $this->runFs('cp', ['a://d', 'b://d']);

        $this->assertFailedOrThrew($result, 'Copy failed');
        $this->assertTree('a', ['d' => self::DIR, 'd/y.txt' => 'yy']);
        $this->assertTree('b', ['d' => '']);
    }

    public function testCpToFilesystemRootThrows(): void
    {
        // NOTE: current behaviour: the target `b://` is no file path, the target is not completed with the source name
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('cp', ['a://x.txt', 'b://']);

        $this->assertThrew($result);
        $this->assertTree('b', []);
    }

    public function testCpWithUnknownSchemeThrowsAndChangesNothing(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');
        $before = $this->snapshot();

        $this->assertThrew($this->runFs('cp', ['a://x.txt', 'zz://x.txt']));
        $this->assertThrew($this->runFs('cp', ['zz://x.txt', 'a://y.txt']));

        $this->assertTreeUnchanged($before);
    }

    // ------------------------------------------------------------------
    // mv
    // ------------------------------------------------------------------

    public function testMvMovesFileAcrossFilesystems(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('mv', ['a://x.txt', 'b://m.txt']);

        $this->assertOk($result);
        $this->assertTree('a', []);
        $this->assertTree('b', ['m.txt' => 'hello']);
    }

    public function testMvRenamesFileWithinOneFilesystem(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');
        $this->seedFile('a', 'other.txt', 'o');

        $result = $this->runFs('mv', ['a://x.txt', 'a://renamed.txt']);

        $this->assertOk($result);
        $this->assertTree('a', ['renamed.txt' => 'hello', 'other.txt' => 'o']);
    }

    public function testMvCreatesMissingParentDirectoriesOfTarget(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('mv', ['a://x.txt', 'b://sub/m.txt']);

        $this->assertOk($result);
        $this->assertTree('b', ['sub' => self::DIR, 'sub/m.txt' => 'hello']);
    }

    public function testMvOntoExistingFileThrowsAndKeepsBothFiles(): void
    {
        $this->seedFile('a', 'x.txt', 'new');
        $this->seedFile('b', 'x.txt', 'old');

        $result = $this->runFs('mv', ['a://x.txt', 'b://x.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', ['x.txt' => 'new']);
        $this->assertTree('b', ['x.txt' => 'old']);
    }

    public function testMvOntoItselfThrowsAndKeepsFile(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('mv', ['a://x.txt', 'a://x.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', ['x.txt' => 'hello']);
    }

    public function testMvOfMissingSourceThrowsAndCreatesNothing(): void
    {
        $result = $this->runFs('mv', ['a://nope.txt', 'b://m.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', []);
        $this->assertTree('b', []);
    }

    public function testMvOfDirectoryFailsKeepsSourceAndLeavesAnEmptyFileAtTheTarget(): void
    {
        // NOTE: current behaviour, looks unintended: same as cp, an empty FILE is left at the target;
        // macOS throws (PHP notice turned into an exception), Linux returns exit code 1 with `Move failed`
        $this->seedFile('a', 'd/y.txt', 'yy');

        $result = $this->runFs('mv', ['a://d', 'b://dd']);

        $this->assertFailedOrThrew($result, 'Move failed');
        $this->assertTree('a', ['d' => self::DIR, 'd/y.txt' => 'yy']);
        $this->assertTree('b', ['dd' => '']);
    }

    // ------------------------------------------------------------------
    // rm
    // ------------------------------------------------------------------

    public function testRmDeletesFile(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');
        $this->seedFile('a', 'keep.txt', 'k');

        $result = $this->runFs('rm', ['a://x.txt']);

        $this->assertOk($result);
        $this->assertTree('a', ['keep.txt' => 'k']);
    }

    public function testRmDeletesFileInSubdirectoryAndKeepsTheDirectory(): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy');

        $result = $this->runFs('rm', ['a://d/y.txt']);

        $this->assertOk($result);
        $this->assertTree('a', ['d' => self::DIR]);
    }

    public function testRmOnlyTouchesTheAddressedFilesystem(): void
    {
        $this->seedFile('a', 'x.txt', 'in a');
        $this->seedFile('b', 'x.txt', 'in b');

        $this->runFs('rm', ['b://x.txt']);

        $this->assertTree('a', ['x.txt' => 'in a']);
        $this->assertTree('b', []);
    }

    public function testRmOfDirectoryFailsWithExitCodeOne(): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy');
        $this->seedDir('a', 'empty');

        $this->assertFailed($this->runFs('rm', ['a://d']), 'Deletion failed');
        $this->assertFailed($this->runFs('rm', ['a://empty']), 'Deletion failed');

        $this->assertTree('a', ['d' => self::DIR, 'd/y.txt' => 'yy', 'empty' => self::DIR]);
    }

    public function testRmOfMissingFileThrows(): void
    {
        // NOTE: current behaviour, looks inconsistent: rmdir of a missing directory returns exit code 1, rm throws
        $result = $this->runFs('rm', ['a://nope.txt']);

        $this->assertThrew($result);
        $this->assertTree('a', []);
    }

    // ------------------------------------------------------------------
    // rmdir
    // ------------------------------------------------------------------

    public function testRmdirDeletesEmptyDirectory(): void
    {
        $this->seedDir('a', 'empty');
        $this->seedFile('a', 'keep.txt', 'k');

        $result = $this->runFs('rmdir', ['a://empty']);

        $this->assertOk($result);
        $this->assertTree('a', ['keep.txt' => 'k']);
    }

    public function testRmdirDeletesDirectoryWithItsWholeContent(): void
    {
        // NOTE: current behaviour: no `recursive` option needed, nested files and directories are removed
        $this->seedFile('a', 'd/y.txt', 'yy');
        $this->seedFile('a', 'd/e/z.txt', 'z');
        $this->seedFile('a', 'keep.txt', 'k');

        $result = $this->runFs('rmdir', ['a://d']);

        $this->assertOk($result);
        $this->assertTree('a', ['keep.txt' => 'k']);
    }

    public function testRmdirOfMissingDirectoryFailsWithExitCodeOne(): void
    {
        $result = $this->runFs('rmdir', ['a://nope']);

        $this->assertFailed($result, 'Deletion of directory failed');
    }

    public function testRmdirOfFileFailsWithExitCodeOneAndKeepsFile(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');

        $result = $this->runFs('rmdir', ['a://x.txt']);

        $this->assertFailed($result, 'Deletion of directory failed');
        $this->assertTree('a', ['x.txt' => 'hello']);
    }

    public function testRmdirOfFilesystemRootThrowsAndKeepsContent(): void
    {
        $this->seedFile('a', 'x.txt', 'hello');
        $this->seedFile('a', 'd/y.txt', 'yy');

        $result = $this->runFs('rmdir', ['a://']);

        $this->assertThrew($result);
        $this->assertTree('a', ['x.txt' => 'hello', 'd' => self::DIR, 'd/y.txt' => 'yy']);
    }

    // ------------------------------------------------------------------
    // sync: result on disk (console action and FsManager::sync())
    // ------------------------------------------------------------------

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncCopiesFileToEmptyTarget(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('a', ['x.txt' => 'hello']);
        $this->assertTree('b', ['x.txt' => 'hello']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncIsNotRecursiveByDefault(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'top', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'nested', self::OLD);
        $this->seedFile('a', 'd/e/z.txt', 'deep', self::OLD);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['x.txt' => 'top']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncRecursiveCopiesNestedFilesAndCreatesTheirDirectories(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'top', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'nested', self::OLD);
        $this->seedFile('a', 'd/e/z.txt', 'deep', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', [
            'x.txt' => 'top',
            'd' => self::DIR,
            'd/y.txt' => 'nested',
            'd/e' => self::DIR,
            'd/e/z.txt' => 'deep',
        ]);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncDoesNotChangeTheSource(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'top', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'nested', self::OLD);
        $this->seedDir('a', 'empty');
        $before = $this->tree('a');

        $this->sync($via, 'a://', 'b://', true);

        $this->assertSame($before, $this->tree('a'));
        $this->assertSame(self::OLD, $this->mtime('a', 'x.txt'));
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncDoesNotCreateEmptySourceDirectoriesAtTheTarget(string $via): void
    {
        // NOTE: current behaviour, looks unintended: directory entries are skipped, so an empty directory
        // (and a directory that only contains empty directories) never appears at the target
        $this->seedDir('a', 'empty');
        $this->seedDir('a', 'outer/inner');
        $this->seedFile('a', 'x.txt', 'top', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', ['x.txt' => 'top']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfOnlyEmptyDirectoriesLeavesTargetEmpty(string $via): void
    {
        $this->seedDir('a', 'empty');

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', []);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSkipsFileWhenTargetIsNewer(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'source', self::OLD);
        $this->seedFile('b', 'x.txt', 'target', self::NEW);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['x.txt' => 'target']);
        $this->assertSame(self::NEW, $this->mtime('b', 'x.txt'));
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSkipsFileWhenMtimeIsEqual(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'source', self::MID);
        $this->seedFile('b', 'x.txt', 'target', self::MID);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['x.txt' => 'target']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncReplacesFileWhenTargetIsOlder(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'newer source', self::NEW);
        $this->seedFile('b', 'x.txt', 'older target with longer content', self::OLD);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        // the whole content is replaced (no rest of the longer old content), the source stays as it is
        $this->assertTree('b', ['x.txt' => 'newer source']);
        $this->assertTree('a', ['x.txt' => 'newer source']);
        $this->assertSame(self::NEW, $this->mtime('a', 'x.txt'));
        // like a copy, the replaced file gets the current time, so a second run skips it
        $this->assertGreaterThan(self::NEW, $this->mtime('b', 'x.txt'));
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncReplacesOlderTargetsAndContinuesWithTheFollowingFiles(string $via): void
    {
        $this->seedFile('a', 'a.txt', 'source a', self::NEW);
        $this->seedFile('b', 'a.txt', 'target a', self::OLD);
        $this->seedFile('a', 'b.txt', 'source b', self::OLD);
        $this->seedFile('a', 'c.txt', 'source c', self::OLD);
        $this->seedFile('b', 'c.txt', 'target c', self::NEW);
        $this->seedFile('a', 'd/e.txt', 'source e', self::NEW);
        $this->seedFile('b', 'd/e.txt', 'target e', self::OLD);
        $this->seedFile('b', 'd/only-target.txt', 'mine', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', [
            'a.txt' => 'source a',
            'b.txt' => 'source b',
            'c.txt' => 'target c',
            'd' => self::DIR,
            'd/e.txt' => 'source e',
            'd/only-target.txt' => 'mine',
        ]);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOneSecondNewerSourceAlreadyReplacesTheTarget(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'source', self::MID + 1);
        $this->seedFile('b', 'x.txt', 'target', self::MID);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['x.txt' => 'source']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOneSecondOlderSourceIsSkipped(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'source', self::MID - 1);
        $this->seedFile('b', 'x.txt', 'target', self::MID);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['x.txt' => 'target']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncCopiesNewFilesAndSkipsExistingOnesInTheSameRun(string $via): void
    {
        $this->seedFile('a', 'new.txt', 'new', self::OLD);
        $this->seedFile('a', 'same.txt', 'source', self::MID);
        $this->seedFile('b', 'same.txt', 'target', self::MID);
        $this->seedFile('a', 'behind.txt', 'source', self::OLD);
        $this->seedFile('b', 'behind.txt', 'target', self::NEW);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['new.txt' => 'new', 'same.txt' => 'target', 'behind.txt' => 'target']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncKeepsFilesThatExistOnlyInTheTarget(string $via): void
    {
        // NOTE: current behaviour: sync never deletes, it is a one-way copy
        $this->seedFile('a', 'x.txt', 'source', self::OLD);
        $this->seedFile('b', 'only-target.txt', 'mine', self::OLD);
        $this->seedFile('b', 'dir/only-target.txt', 'mine too', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', [
            'x.txt' => 'source',
            'only-target.txt' => 'mine',
            'dir' => self::DIR,
            'dir/only-target.txt' => 'mine too',
        ]);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSetsMtimeOfCopiedFileToNowSoSecondRunSkipsIt(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $this->sync($via, 'a://', 'b://');
        $copiedMtime = $this->mtime('b', 'x.txt');
        $second = $this->sync($via, 'a://', 'b://');

        // NOTE: current behaviour: the copy does not keep the source mtime
        $this->assertGreaterThan(self::NEW, $copiedMtime);
        $this->assertNull($second->exception);
        $this->assertTree('b', ['x.txt' => 'hello']);
        $this->assertSame($copiedMtime, $this->mtime('b', 'x.txt'));
    }

    // ------------------------------------------------------------------
    // sync: target path rules (`$dest . $entry['path']`)
    // ------------------------------------------------------------------

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfSubdirectoryKeepsTheSourceDirectoryNameBelowTheTarget(string $via): void
    {
        // target path = dest . path-relative-to-filesystem-root: the listed directory itself is part of the path
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);
        $this->seedFile('a', 'x.txt', 'top', self::OLD);

        $result = $this->sync($via, 'a://d', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['d' => self::DIR, 'd/y.txt' => 'yy']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfSubdirectoryToTargetWithTrailingSlashPutsItBelowThatDirectory(string $via): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);

        $result = $this->sync($via, 'a://d', 'b://t/');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['t' => self::DIR, 't/d' => self::DIR, 't/d/y.txt' => 'yy']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncToTargetWithoutTrailingSlashGluesTheNameOntoTheTargetName(string $via): void
    {
        // NOTE: current behaviour, looks unintended: `b://t` + `d/y.txt` = `b://td/y.txt`, no directory `t`
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);

        $result = $this->sync($via, 'a://d', 'b://t');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['td' => self::DIR, 'td/y.txt' => 'yy']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfRootFilesToTargetWithoutTrailingSlashGluesNameOntoTargetName(string $via): void
    {
        // NOTE: current behaviour, looks unintended: `b://sub` + `x.txt` = `b://subx.txt`
        $this->seedFile('a', 'x.txt', 'top', self::OLD);

        $result = $this->sync($via, 'a://', 'b://sub');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['subx.txt' => 'top']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfRootFilesToTargetWithTrailingSlashPutsThemBelowThatDirectory(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'top', self::OLD);

        $result = $this->sync($via, 'a://', 'b://sub/');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['sub' => self::DIR, 'sub/x.txt' => 'top']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncRecursiveWithoutTargetTrailingSlashGluesTopLevelNamesOntoTargetName(string $via): void
    {
        // NOTE: current behaviour, looks unintended: every path is prefixed with `sub` without a separator
        $this->seedFile('a', 'x.txt', 'top', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);

        $result = $this->sync($via, 'a://', 'b://sub', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', ['subx.txt' => 'top', 'subd' => self::DIR, 'subd/y.txt' => 'yy']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSourceWithAndWithoutTrailingSlashGiveTheSameTarget(string $via): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);
        $this->seedFile('a', 'd/e/z.txt', 'z', self::OLD);

        $this->sync($via, 'a://d/', 'b://t/', true);

        $this->assertTree('b', [
            't' => self::DIR,
            't/d' => self::DIR,
            't/d/y.txt' => 'yy',
            't/d/e' => self::DIR,
            't/d/e/z.txt' => 'z',
        ]);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSubdirectoryRecursiveKeepsTheDirectoryPrefixForNestedFiles(string $via): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);
        $this->seedFile('a', 'd/e/z.txt', 'z', self::OLD);
        $this->seedFile('a', 'other/o.txt', 'o', self::OLD);

        $result = $this->sync($via, 'a://d', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', [
            'd' => self::DIR,
            'd/y.txt' => 'yy',
            'd/e' => self::DIR,
            'd/e/z.txt' => 'z',
        ]);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncTargetPathWithDoubleSlashIsNormalised(string $via): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);

        $result = $this->sync($via, 'a://d/', 'b:///t/');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['t' => self::DIR, 't/d' => self::DIR, 't/d/y.txt' => 'yy']);
    }

    // ------------------------------------------------------------------
    // sync: same scheme as source and target
    // ------------------------------------------------------------------

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncRootOntoItselfChangesNothing(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);
        $before = $this->snapshot();

        $result = $this->sync($via, 'a://', 'a://', true);

        $this->assertNull($result->exception);
        $this->assertTreeUnchanged($before);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSubdirectoryOntoItselfWithoutSlashCreatesSiblingNamedDoubleName(string $via): void
    {
        // NOTE: current behaviour, looks unintended: `a://d` + `d/y.txt` = `a://dd/y.txt`
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);

        $result = $this->sync($via, 'a://d', 'a://d', true);

        $this->assertNull($result->exception);
        $this->assertTree('a', ['d' => self::DIR, 'd/y.txt' => 'yy', 'dd' => self::DIR, 'dd/y.txt' => 'yy']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncWithinOneFilesystemCopiesToTheOtherPath(string $via): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);

        $result = $this->sync($via, 'a://d', 'a://copy/', true);

        $this->assertNull($result->exception);
        $this->assertTree('a', [
            'd' => self::DIR,
            'd/y.txt' => 'yy',
            'copy' => self::DIR,
            'copy/d' => self::DIR,
            'copy/d/y.txt' => 'yy',
        ]);
        $this->assertTree('b', []);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncFromSecondToFirstFilesystem(string $via): void
    {
        $this->seedFile('b', 'x.txt', 'from b', self::OLD);

        $result = $this->sync($via, 'b://', 'a://');

        $this->assertNull($result->exception);
        $this->assertTree('a', ['x.txt' => 'from b']);
        $this->assertTree('b', ['x.txt' => 'from b']);
    }

    // ------------------------------------------------------------------
    // sync: source and target of different kind, missing source, unknown scheme
    // ------------------------------------------------------------------

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfMissingSourceDoesNothing(string $via): void
    {
        // NOTE: current behaviour, looks unintended: a missing source is not an error
        $this->seedFile('b', 'keep.txt', 'k', self::OLD);
        $before = $this->snapshot();

        $result = $this->sync($via, 'a://nope', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTreeUnchanged($before);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfFilePathAsSourceDoesNothing(string $via): void
    {
        // NOTE: current behaviour, looks unintended: a file as source lists nothing, so nothing is copied
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $result = $this->sync($via, 'a://x.txt', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', []);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncSkipsFileWhenTargetIsADirectoryWithNewerMtime(string $via): void
    {
        // NOTE: current behaviour, looks unintended: the directory counts as "existing target", its mtime is compared
        $this->seedFile('a', 'n', 'file', self::OLD);
        $this->seedFile('b', 'n/inner.txt', 'inner', self::OLD);
        $this->touchPath('b', 'n', self::NEW);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['n' => self::DIR, 'n/inner.txt' => 'inner']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncThrowsWhenFileIsNewerThanTheDirectoryAtTheTarget(string $via): void
    {
        // NOTE: current behaviour, looks unintended: the directory is never replaced, the copy throws
        $this->seedFile('a', 'n', 'file', self::NEW);
        $this->seedFile('b', 'n/inner.txt', 'inner', self::OLD);
        $this->touchPath('b', 'n', self::MID);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNotNull($result->exception);
        $this->assertTree('b', ['n' => self::DIR, 'n/inner.txt' => 'inner']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncThrowsWhenSourceHasDirectoryWhereTargetHasFile(string $via): void
    {
        // NOTE: current behaviour, looks unintended: files below the directory cannot be written, the file stays
        $this->seedFile('a', 'k/inner.txt', 'inner', self::OLD);
        $this->seedFile('b', 'k', 'i am a file', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNotNull($result->exception);
        $this->assertTree('b', ['k' => 'i am a file']);
        $this->assertTree('a', ['k' => self::DIR, 'k/inner.txt' => 'inner']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncOfDirectoryOntoExistingDirectoryMergesContent(string $via): void
    {
        $this->seedFile('a', 'd/new.txt', 'new', self::OLD);
        $this->seedFile('b', 'd/old.txt', 'old', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', ['d' => self::DIR, 'd/old.txt' => 'old', 'd/new.txt' => 'new']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncWithUnknownTargetSchemeThrowsAndChangesNothing(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);
        $before = $this->snapshot();

        $result = $this->sync($via, 'a://', 'zz://', true);

        $this->assertNotNull($result->exception);
        $this->assertTreeUnchanged($before);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncWithUnknownSourceSchemeThrowsAndChangesNothing(string $via): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);
        $before = $this->snapshot();

        $result = $this->sync($via, 'zz://', 'b://', true);

        $this->assertNotNull($result->exception);
        $this->assertTreeUnchanged($before);
    }

    // ------------------------------------------------------------------
    // sync: file content
    // ------------------------------------------------------------------

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncCopiesEmptyFile(string $via): void
    {
        $this->seedFile('a', 'empty.txt', '', self::OLD);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertTree('b', ['empty.txt' => '']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncCopiesLargeBinaryFileWithIdenticalContent(string $via): void
    {
        $blob = self::blob(1024 * 1024 + 123);
        $this->seedFile('a', 'big.bin', $blob, self::OLD);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertSame(sha1($blob), $this->tree('b')['big.bin']);
        $this->assertSame(strlen($blob), filesize($this->diskPath('b', 'big.bin')));
        $this->assertSame($this->tree('a'), $this->tree('b'));
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncCopiesBinaryContentIncludingNullBytesAndNewlines(string $via): void
    {
        $content = "\0\1\2\r\n\n\xff\xfe" . self::blob(300);
        $this->seedFile('a', 'bin.dat', $content, self::OLD);

        $result = $this->sync($via, 'a://', 'b://');

        $this->assertNull($result->exception);
        $this->assertSame(sha1($content), $this->tree('b')['bin.dat']);
    }

    /**
     * @dataProvider syncViaProvider
     */
    public function testSyncCopiesFilesWithSpacesAndUnicodeInTheirNames(string $via): void
    {
        $this->seedFile('a', "my docs/sch\u{f6}n file.txt", 'umlaut', self::OLD);

        $result = $this->sync($via, 'a://', 'b://', true);

        $this->assertNull($result->exception);
        $this->assertTree('b', ['my docs' => self::DIR, "my docs/sch\u{f6}n file.txt" => 'umlaut']);
    }

    // ------------------------------------------------------------------
    // sync: console action, exit code and output
    // ------------------------------------------------------------------

    public function testSyncActionPrintsPlusForCopiedFileAndDone(): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $result = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($result, "+\nDone.\n");
    }

    public function testSyncActionPrintsDotForSkippedFileAndDone(): void
    {
        $this->seedFile('a', 'x.txt', 'source', self::OLD);
        $this->seedFile('b', 'x.txt', 'target', self::NEW);

        $result = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($result, ".\nDone.\n");
    }

    public function testSyncActionPrintsDotForEqualMtime(): void
    {
        $this->seedFile('a', 'x.txt', 'source', self::MID);
        $this->seedFile('b', 'x.txt', 'target', self::MID);

        $result = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($result, ".\nDone.\n");
    }

    public function testSyncActionSecondRunPrintsDot(): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $this->runFs('sync', ['a://', 'b://']);
        $second = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($second, ".\nDone.\n");
    }

    public function testSyncActionReportsSkippedDirectoryByName(): void
    {
        $this->seedDir('a', 'empty');

        $result = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($result, "Skipped directory 'empty'\n\nDone.\n");
    }

    public function testSyncActionRecursiveReportsEveryDirectoryAndEveryFile(): void
    {
        $this->seedFile('a', 'x.txt', 'top', self::OLD);
        $this->seedFile('a', 'd/y.txt', 'nested', self::OLD);
        $this->seedFile('a', 'd/e/z.txt', 'deep', self::OLD);

        $result = $this->runFs('sync', ['a://', 'b://'], ['recursive' => 1]);

        // the order of entries is the order of the adapter, so the output is compared as a multiset of tokens
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('', $result->stderr);
        $this->assertSame("\nDone.\n", substr($result->stdout, -7));
        $body = substr($result->stdout, 0, -7);
        preg_match_all("/Skipped directory '[^']*'|\\+|\\./", $body, $matches);
        $tokens = $matches[0];
        sort($tokens);
        $this->assertSame(["+", "+", "+", "Skipped directory 'd'", "Skipped directory 'd/e'"], $tokens);
        $this->assertSame(strlen($body), strlen(implode('', $tokens)) + substr_count($body, "\n"), 'only newlines besides tokens');
    }

    public function testSyncActionOfMissingSourcePrintsOnlyNewlineAndDone(): void
    {
        $result = $this->runFs('sync', ['a://nope', 'b://']);

        $this->assertOk($result, "\nDone.\n");
    }

    public function testSyncActionWithNothingToDoPrintsOnlyNewlineAndDone(): void
    {
        $result = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($result, "\nDone.\n");
    }

    public function testSyncActionOfSubdirectoryNonRecursiveReportsNestedDirectory(): void
    {
        $this->seedFile('a', 'd/y.txt', 'yy', self::OLD);
        $this->seedDir('a', 'd/sub');

        $result = $this->runFs('sync', ['a://d', 'b://']);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame(2, preg_match_all("/\\+|Skipped directory 'd\\/sub'\n/", $result->stdout));
        $this->assertSame("\nDone.\n", substr($result->stdout, -7));
        $this->assertTree('b', ['d' => self::DIR, 'd/y.txt' => 'yy']);
    }

    public function testSyncActionPrintsPlusForReplacedOlderTargetAndDone(): void
    {
        $this->seedFile('a', 'x.txt', 'newer source', self::NEW);
        $this->seedFile('b', 'x.txt', 'older target', self::OLD);

        $result = $this->runFs('sync', ['a://', 'b://']);

        $this->assertOk($result, "+\nDone.\n");
        $this->assertTree('b', ['x.txt' => 'newer source']);
    }

    public function testSyncActionPrintsOneTokenPerFileForNewReplacedAndSkippedFiles(): void
    {
        $this->seedFile('a', 'new.txt', 'new', self::OLD);
        $this->seedFile('a', 'older.txt', 'source', self::NEW);
        $this->seedFile('b', 'older.txt', 'target', self::OLD);
        $this->seedFile('a', 'newer.txt', 'source', self::OLD);
        $this->seedFile('b', 'newer.txt', 'target', self::NEW);

        $result = $this->runFs('sync', ['a://', 'b://']);

        // the order of entries is the order of the adapter, so the output is compared as a multiset of tokens
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('', $result->stderr);
        $this->assertSame("\nDone.\n", substr($result->stdout, -7));
        $tokens = str_split(substr($result->stdout, 0, -7));
        sort($tokens);
        $this->assertSame(['+', '+', '.'], $tokens);
    }

    public function testSyncActionWithUnknownSchemeThrowsAndPrintsNothing(): void
    {
        $result = $this->runFs('sync', ['a://', 'zz://']);

        $this->assertThrew($result);
        $this->assertSame('', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testSyncActionIgnoresOptionLongAsItOnlyAffectsLs(): void
    {
        $this->seedFile('a', 'x.txt', 'hello', self::OLD);

        $result = $this->runFs('sync', ['a://', 'b://'], ['long' => 1]);

        $this->assertOk($result, "+\nDone.\n");
    }
}
