<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\console;

use hrzg\filefly\tests\adapters\suite\support\TestRoot;

/**
 * `fs sync` between local and FTP (Pure-FTPd 1.0.54, explicit FTPS, compose service `pureftpd`).
 */
class PureftpdFtpsSyncTest extends RemoteSyncTestCase
{
    protected function createRemoteRoot(): TestRoot
    {
        return TestRoot::ftp('pureftpd', true);
    }
}
