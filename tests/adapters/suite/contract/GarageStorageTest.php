<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The storage contract on Garage (S3 without object ACLs), compose service `garage`.
 */
class GarageStorageTest extends S3StorageContractTestCase
{
    protected function endpoint(): string
    {
        return 'http://garage:3900';
    }
}
