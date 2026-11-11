<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\console;

use hrzg\filefly\tests\adapters\suite\support\TestRoot;

/**
 * `fs sync` between local and FTP (vsftpd 3.0.5, explicit FTPS, compose service `vsftpd`).
 */
class VsftpdFtpsSyncTest extends RemoteSyncTestCase
{
    protected function createRemoteRoot(): TestRoot
    {
        return TestRoot::ftp('vsftpd', true);
    }
}
