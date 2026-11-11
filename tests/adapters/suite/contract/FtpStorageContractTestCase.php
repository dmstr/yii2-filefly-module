<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

use hrzg\filefly\components\Storage;
use hrzg\filefly\components\storage\StorageException;
use hrzg\filefly\tests\adapters\suite\support\TestRoot;
use League\Flysystem\UnableToWriteFile;

/**
 * The storage contract on an FTP daemon, plain FTP or explicit FTPS. Each test gets its own
 * root directory (TestRoot::ftp()).
 */
abstract class FtpStorageContractTestCase extends StorageContractTestCase
{
    /**
     * @var TestRoot|null
     */
    private $root;

    /**
     * Compose service name of the daemon
     */
    abstract protected function host(): string;

    /**
     * Explicit FTPS (AUTH TLS) instead of plain FTP
     */
    abstract protected function ssl(): bool;

    protected function deviations(): array
    {
        return [Deviation::DIRECTORY_LAST_MODIFIED_NONE, Deviation::LISTING_TIMESTAMPS_MINUTES];
    }

    protected function createTestRoot(): array
    {
        $this->root = TestRoot::ftp($this->host(), $this->ssl());
        return $this->root->config;
    }

    protected function removeTestRoot(): void
    {
        if ($this->root !== null) {
            $this->root->remove();
        }
        $this->root = null;
    }

    // ------------------------------------------------------------------
    // PHP warnings of ext-ftp
    // ------------------------------------------------------------------

    /**
     * Runs $operation under an error handler that turns every warning into an exception, as
     * yii\base\ErrorHandler does in an application; returns the warnings it saw
     */
    private function underThrowingErrorHandler(callable $operation): array
    {
        $seen = [];
        set_error_handler(static function ($level, $message, $file, $line) use (&$seen) {
            $seen[] = $message;
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            $operation();
        } finally {
            restore_error_handler();
        }
        return $seen;
    }

    public function testFailedUploadEndsInTheFacadesExceptionNotInAPhpWarning(): void
    {
        $this->seedFile('f.txt', 'F');

        $seen = $this->underThrowingErrorHandler(function () {
            $this->assertThrows(StorageException::class, function () {
                $this->storage->writeFile('f.txt/child.txt', self::stream('C'));
            });
            $this->assertThrows(StorageException::class, function () {
                $this->storage->replaceFile('f.txt/child.txt', self::stream('C'));
            });
        });

        $this->assertSame([], $seen, 'no warning reached the application error handler');
        $this->assertTree(['f.txt' => 'F']);
    }

    /**
     * A warning that is not followed by an exception of the library is a real error: it reaches
     * the error handler that was active, unchanged (and the facade's own handler is removed)
     */
    public function testWarningsWithoutLibraryFailureArePassedOnUnchanged(): void
    {
        $libraryCall = new \ReflectionMethod(Storage::class, 'libraryCall');
        $libraryCall->setAccessible(true);
        $seen = [];
        set_error_handler(static function ($level, $message) use (&$seen) {
            $seen[] = [$level, $message];
            return true;
        });
        try {
            $result = $libraryCall->invoke($this->storage, static function () {
                trigger_error('first', E_USER_WARNING);
                trigger_error('second', E_USER_NOTICE);
                @trigger_error('silenced', E_USER_WARNING);
                return 'result';
            });
            $this->assertSame('result', $result);
            $this->assertSame([[E_USER_WARNING, 'first'], [E_USER_NOTICE, 'second']], $seen);

            $seen = [];
            try {
                $libraryCall->invoke($this->storage, static function () {
                    trigger_error('before a foreign exception', E_USER_WARNING);
                    throw new \RuntimeException('foreign');
                });
                $this->fail('the exception was swallowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('foreign', $e->getMessage());
            }
            $this->assertSame([[E_USER_WARNING, 'before a foreign exception']], $seen);

            $seen = [];
            try {
                $libraryCall->invoke($this->storage, static function () {
                    trigger_error('belongs to the failure', E_USER_WARNING);
                    throw UnableToWriteFile::atLocation('x.txt', 'failed');
                });
                $this->fail('the exception was swallowed');
            } catch (UnableToWriteFile $e) {
                // passed on to call(), which translates it
            }
            $this->assertSame([], $seen, 'a warning ahead of a library exception is dropped');

            trigger_error('after', E_USER_WARNING);
            $this->assertSame([[E_USER_WARNING, 'after']], $seen, 'the previous handler is active again');
        } finally {
            restore_error_handler();
        }
    }
}
