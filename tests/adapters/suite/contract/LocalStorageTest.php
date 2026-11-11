<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

use hrzg\filefly\tests\adapters\suite\support\Backends;

/**
 * The storage contract on the local adapter (creocoder LocalFilesystem as UPGRADE.md recommends
 * it), each test in a fresh temp directory.
 */
class LocalStorageTest extends StorageContractTestCase
{
    private const DIR_PREFIX = 'filefly-contract-';

    /**
     * @var string|null
     */
    private $root;

    protected function deviations(): array
    {
        return [Deviation::DIRECTORY_OPENS_AS_STREAM];
    }

    protected function createTestRoot(): array
    {
        $this->root = realpath(sys_get_temp_dir()) . '/' . Backends::uniqueName(rtrim(self::DIR_PREFIX, '-'));
        mkdir($this->root, 0755);
        return Backends::local($this->root);
    }

    protected function removeTestRoot(): void
    {
        if ($this->root !== null) {
            Backends::removeLocalTree($this->root, self::DIR_PREFIX);
        }
    }
}
