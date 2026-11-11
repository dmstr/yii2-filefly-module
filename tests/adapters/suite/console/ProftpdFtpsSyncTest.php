<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\console;

use hrzg\filefly\tests\adapters\suite\support\TestRoot;

/**
 * `fs sync` between local and FTP (ProFTPD 1.3.9e, explicit FTPS, compose service `proftpd`).
 */
class ProftpdFtpsSyncTest extends RemoteSyncTestCase
{
    protected function createRemoteRoot(): TestRoot
    {
        return TestRoot::ftp('proftpd', true);
    }
}
