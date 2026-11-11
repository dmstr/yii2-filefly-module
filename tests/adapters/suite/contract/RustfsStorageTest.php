<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The storage contract on RustFS (S3 with object ACLs), compose service `rustfs`.
 */
class RustfsStorageTest extends S3StorageContractTestCase
{
    protected function endpoint(): string
    {
        return 'http://rustfs:9000';
    }
}
