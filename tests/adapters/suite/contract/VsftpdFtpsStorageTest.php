<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The storage contract on vsftpd 3.0.5, explicit FTPS, compose service `vsftpd`.
 */
class VsftpdFtpsStorageTest extends FtpStorageContractTestCase
{
    protected function host(): string
    {
        return 'vsftpd';
    }

    protected function ssl(): bool
    {
        return true;
    }
}
