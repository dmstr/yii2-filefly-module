<?php
// file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\tests\support\ApiTestCase;

/**
 * Proves each harness mechanism once against the real module code.
 * Exhaustive API coverage lives in the other tests/api/ files.
 */
class HarnessSmokeTest extends ApiTestCase
{
    public function testAdminListsSeededDirectory(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/docs');
        $this->seedFile('/docs/a.txt', 'hello');

        $response = $this->apiPost('list', ['path' => '/docs']);

        $this->assertStatus(200, $response);
        $result = $response->json()['result'];
        $this->assertCount(1, $result);
        $this->assertSame('a.txt', $result[0]['name']);
        $this->assertSame('file', $result[0]['type']);
        $this->assertSame(5, $result[0]['size']);
        // list repairs the missing hashmap row with the module's default permissions
        $this->assertHashmapRow('/docs/a.txt', ['type' => 'file', 'access_owner' => '1', 'access_read' => '*']);
    }

    public function testAdminCreatesFolder(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('createFolder', ['newPath' => '/new']);

        $this->assertApiSuccess($response);
        $this->assertDirOnDisk('/new');
        $this->assertHashmapRow('/new', ['type' => 'dir', 'access_owner' => (string)self::ADMIN_ID]);
    }

    public function testAdminUploadsFile(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['hello.txt' => 'Hello upload']);

        $this->assertApiSuccess($response);
        $this->assertSame(['hello.txt'], $response->json()['result']['uploadedFiles']);
        $this->assertFileOnDisk('/hello.txt', 'Hello upload');
        $this->assertHashmapRow('/hello.txt', ['type' => 'file', 'size' => '12']);
    }

    public function testAdminStreamsFile(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/s.txt', 'streamed content');

        $response = $this->apiStream('/s.txt');

        $this->assertStatus(200, $response);
        $this->assertSame('streamed content', $response->body);
        $this->assertStringStartsWith('text/plain', (string)$response->header('Content-Type'));
        $this->assertStringStartsWith('inline', (string)$response->header('Content-Disposition'));
    }

    public function testUserWithoutPermissionsIsDenied(): void
    {
        $this->actingAsUser(5);
        $this->seedFile('/a.txt', 'x');

        $response = $this->apiPost('list', ['path' => '/']);

        // ApiController::runAction() requires the FileflyDefault role for every non-GET request
        $this->assertStatus(403, $response);
        $this->assertSame('Action not allowed', $response->exception->getMessage());
    }
}
