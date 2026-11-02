<?php

namespace hrzg\filefly\tests;

use hrzg\filefly\components\UploadValidator;
use PHPUnit\Framework\TestCase;

// BaseObject::__construct() needs the Yii class for configuration
if (!class_exists('Yii', false)) {
    require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
}

class UploadValidatorTest extends TestCase
{
    private $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'filefly-upload-');
        file_put_contents($this->tmpFile, str_repeat('a', 2048));
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    private function file($name, array $overrides = [])
    {
        return array_merge([
            'name' => $name,
            'tmp_name' => $this->tmpFile,
            'size' => 2048,
            'error' => UPLOAD_ERR_OK,
        ], $overrides);
    }

    public function testDefaultsAreBackwardsCompatible(): void
    {
        $validator = new UploadValidator();

        $this->assertNull($validator->validateUpload($this->file('demo.pdf.jpg'), 'folder/demo.pdf.jpg'));
        $this->assertNull($validator->validateUpload($this->file('script.php'), 'folder/script.php'));
        $this->assertNull($validator->validateUpload($this->file('README'), 'folder/README'));
    }

    public function testInvalidFileNamesAreAlwaysRejected(): void
    {
        $validator = new UploadValidator();

        $this->assertSame('file_name_invalid', $validator->validateFileName("shell.php\0.jpg"));
        $this->assertSame('file_name_invalid', $validator->validateFileName("line\nbreak.jpg"));
        $this->assertSame('file_name_invalid', $validator->validateFileName('..\\shell.php'));
        $this->assertSame('file_name_invalid', $validator->validateFileName(' . '));
        $this->assertSame('file_name_invalid', $validator->validateUpload($this->file(['a.jpg']), 'a.jpg'));
    }

    public function testDeniedExtensionsAreCheckedEverywhereInTheName(): void
    {
        $validator = new UploadValidator(['deniedExtensions' => UploadValidator::DANGEROUS_EXTENSIONS]);

        $this->assertSame('file_type_not_allowed', $validator->validateFileName('shell.php'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('Shell.PHP'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('shell.php.jpg'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('report.pdf.exe'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('shell.php.'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('shell.php .'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('.htaccess'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('.user.ini'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('image.svg'));
        $this->assertNull($validator->validateFileName('photo.jpg'));
        $this->assertNull($validator->validateFileName('phpinfo.pdf'));
    }

    public function testAllowedExtensionsAreCheckedAgainstTheLastExtension(): void
    {
        $validator = new UploadValidator(['allowedExtensions' => ['pdf', '.JPG']]);

        $this->assertNull($validator->validateFileName('document.pdf'));
        $this->assertNull($validator->validateFileName('Photo.jpg'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('document.docx'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('photo.jpg.exe'));
        $this->assertSame('file_type_not_allowed', $validator->validateFileName('README'));
    }

    public function testMultipleExtensionsCanBeDenied(): void
    {
        $validator = new UploadValidator(['denyMultipleExtensions' => true]);

        $this->assertSame('file_multiple_extensions_not_allowed', $validator->validateFileName('demo.pdf.jpg'));
        $this->assertSame('file_multiple_extensions_not_allowed', $validator->validateFileName('demo.pdf.jpg.'));
        $this->assertNull($validator->validateFileName('demo.jpg'));
        $this->assertNull($validator->validateFileName('demo..jpg'));
    }

    public function testOriginalNameIsCheckedEvenIfTargetNameDiffers(): void
    {
        $validator = new UploadValidator(['denyMultipleExtensions' => true]);

        // slugged target name has a single extension, the uploaded name has two
        $this->assertSame(
            'file_multiple_extensions_not_allowed',
            $validator->validateUpload($this->file('demo.pdf.jpg'), 'folder/demopdf.jpg')
        );
    }

    public function testTargetNameIsChecked(): void
    {
        $validator = new UploadValidator(['deniedExtensions' => ['php']]);

        $this->assertSame('file_type_not_allowed', $validator->validateUpload($this->file('demo.jpg'), 'folder/demo.php'));
    }

    public function testMaxFileSize(): void
    {
        $this->assertNull((new UploadValidator(['maxFileSize' => 2048]))->validateFile($this->file('a.jpg')));
        $this->assertSame('file_too_large', (new UploadValidator(['maxFileSize' => 2047]))->validateFile($this->file('a.jpg')));
        $this->assertSame('file_too_large', (new UploadValidator(['maxFileSize' => '1K']))->validateFile($this->file('a.jpg')));
        // the reported size is not trusted
        $this->assertSame(
            'file_too_large',
            (new UploadValidator(['maxFileSize' => 1024]))->validateFile($this->file('a.jpg', ['size' => 10]))
        );
    }

    public function testUploadErrors(): void
    {
        $validator = new UploadValidator();

        $this->assertSame('file_too_large', $validator->validateFile($this->file('a.jpg', ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => ''])));
        $this->assertSame('file_too_large', $validator->validateFile($this->file('a.jpg', ['error' => UPLOAD_ERR_FORM_SIZE, 'tmp_name' => ''])));
        $this->assertSame('upload_failed', $validator->validateFile($this->file('a.jpg', ['error' => UPLOAD_ERR_PARTIAL])));
        $this->assertSame('upload_failed', $validator->validateFile($this->file('a.jpg', ['tmp_name' => '/does/not/exist'])));
    }

    public function testParseSize(): void
    {
        $this->assertNull(UploadValidator::parseSize(null));
        $this->assertSame(100, UploadValidator::parseSize(100));
        $this->assertSame(100, UploadValidator::parseSize('100'));
        $this->assertSame(512 * 1024, UploadValidator::parseSize('512K'));
        $this->assertSame(50 * 1024 * 1024, UploadValidator::parseSize('50M'));
        $this->assertSame(50 * 1024 * 1024, UploadValidator::parseSize('50mb'));
        $this->assertSame(1024 * 1024 * 1024, UploadValidator::parseSize('1G'));

        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::parseSize('fifty');
    }
}
