<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\api;

/**
 * The HTTP API on Garage (compose service `garage`).
 */
class GarageApiTest extends S3ApiTestCase
{
    protected function endpoint(): string
    {
        return 'http://garage:3900';
    }
}
