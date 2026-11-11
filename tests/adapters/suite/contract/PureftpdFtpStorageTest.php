<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The storage contract on Pure-FTPd 1.0.54, plain FTP, compose service `pureftpd`.
 */
class PureftpdFtpStorageTest extends FtpStorageContractTestCase
{
    protected function host(): string
    {
        return 'pureftpd';
    }

    protected function ssl(): bool
    {
        return false;
    }
}
