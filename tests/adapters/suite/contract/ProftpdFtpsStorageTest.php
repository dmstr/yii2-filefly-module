<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The storage contract on ProFTPD 1.3.9e (TLS 1.2), explicit FTPS, compose service `proftpd`.
 */
class ProftpdFtpsStorageTest extends FtpStorageContractTestCase
{
    protected function host(): string
    {
        return 'proftpd';
    }

    protected function ssl(): bool
    {
        return true;
    }
}
