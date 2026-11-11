<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\console;

use hrzg\filefly\tests\adapters\suite\support\TestRoot;

/**
 * `fs sync` between local and S3 (Garage, compose service `garage`).
 */
class GarageSyncTest extends RemoteSyncTestCase
{
    protected function createRemoteRoot(): TestRoot
    {
        return TestRoot::s3('http://garage:3900');
    }
}
