<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\support;

use DateTimeInterface;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;

/**
 * A FilesystemOperator that records every call and passes it to the wrapped operator: proves on
 * every backend that the facade refuses a path before it calls the library at all.
 */
class CountingOperator implements FilesystemOperator
{
    /**
     * @var string[] method names in call order
     */
    public $calls = [];

    /**
     * @var FilesystemOperator
     */
    private $inner;

    public function __construct(FilesystemOperator $inner)
    {
        $this->inner = $inner;
    }

    private function pass(string $method, array $arguments)
    {
        $this->calls[] = $method;
        return $this->inner->{$method}(...$arguments);
    }

    public function fileExists(string $location): bool
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function directoryExists(string $location): bool
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function has(string $location): bool
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function read(string $location): string
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function readStream(string $location)
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function lastModified(string $path): int
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function fileSize(string $path): int
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function mimeType(string $path): string
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function visibility(string $path): string
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function delete(string $location): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function deleteDirectory(string $location): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->pass(__FUNCTION__, func_get_args());
    }

    public function publicUrl(string $path, array $config = []): string
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }

    public function checksum(string $path, array $config = []): string
    {
        return $this->pass(__FUNCTION__, func_get_args());
    }
}
