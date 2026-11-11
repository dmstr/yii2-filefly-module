<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The storage contract on ProFTPD 1.3.9e, plain FTP, compose service `proftpd`.
 */
class ProftpdFtpStorageTest extends FtpStorageContractTestCase
{
    protected function host(): string
    {
        return 'proftpd';
    }

    protected function ssl(): bool
    {
        return false;
    }
}
