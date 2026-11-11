<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\api;

/**
 * The HTTP API on RustFS (compose service `rustfs`).
 */
class RustfsApiTest extends S3ApiTestCase
{
    protected function endpoint(): string
    {
        return 'http://rustfs:9000';
    }
}
