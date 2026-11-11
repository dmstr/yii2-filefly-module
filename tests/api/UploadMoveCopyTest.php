<?php
// file generated with AI assistance: Claude Code - [2026-11-11 14:00:00 UTC]

namespace hrzg\filefly\tests\api;

use hrzg\filefly\events\FileEvent;
use hrzg\filefly\tests\support\ApiResponse;
use hrzg\filefly\tests\support\ApiTestCase;

/**
 * Characterisation tests for the ApiController actions `upload`, `move` and `copy`.
 *
 * Pins the CURRENT behaviour (also where it looks unintended) of the HTTP API: status code, JSON body,
 * on-disk state and hashmap rows. Collision cases are the most important ones, because they must not
 * change silently when the filesystem library is replaced.
 *
 * Shorthand used below: a hashmap row is summarised as `type|size|owner|read|update|delete`
 * (`-` for NULL), see row(); the disk is summarised as `path => content` (`null` for a directory).
 */
class UploadMoveCopyTest extends ApiTestCase
{
    // ------------------------------------------------------------------
    // private helpers
    // ------------------------------------------------------------------

    /**
     * File on disk plus hashmap row, like an item created through the API (default access: owner 1, `*`)
     */
    private function seedItem(string $path, string $content = '', array $access = []): void
    {
        $this->seedFile($path, $content);
        $this->seedHashmapRow($path, 'file', $access, strlen($content));
    }

    /**
     * Directory on disk plus hashmap row
     */
    private function seedFolder(string $path, array $access = []): void
    {
        $this->seedDir($path);
        $this->seedHashmapRow($path, 'dir', $access);
    }

    /**
     * All hashmap rows as `/path => 'type|size|owner|read|update|delete'`, sorted by path
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->hashmapRows() as $row) {
            $rows[$row['path']] = implode('|', array_map(static function ($value) {
                return $value === null ? '-' : $value;
            }, [
                $row['type'],
                $row['size'],
                $row['access_owner'],
                $row['access_read'],
                $row['access_update'],
                $row['access_delete'],
            ]));
        }
        ksort($rows);
        return $rows;
    }

    private function row(?string $type, $size, string $owner = '1', string $read = '*', string $update = '*', string $delete = '*'): string
    {
        return implode('|', array_map(static function ($value) {
            return $value === null ? '-' : (string)$value;
        }, [$type, $size, $owner, $read, $update, $delete]));
    }

    private function fileRow(int $size, string $owner = '1', string $read = '*', string $update = '*', string $delete = '*'): string
    {
        return $this->row('file', $size, $owner, $read, $update, $delete);
    }

    private function dirRow(string $owner = '1', string $read = '*', string $update = '*', string $delete = '*'): string
    {
        return $this->row('dir', null, $owner, $read, $update, $delete);
    }

    /**
     * Asserts status 400 and the exact upload `result`
     */
    private function assertUploadFailed(string $error, array $uploadedFiles, ApiResponse $response): void
    {
        $this->assertStatus(400, $response);
        $this->assertSame(
            ['result' => ['success' => false, 'error' => $error, 'uploadedFiles' => $uploadedFiles]],
            $response->json()
        );
    }

    private function assertUploadSucceeded(array $uploadedFiles, ApiResponse $response): void
    {
        $this->assertStatus(200, $response);
        $this->assertSame(
            ['result' => ['success' => true, 'error' => '', 'uploadedFiles' => $uploadedFiles]],
            $response->json()
        );
    }

    // ------------------------------------------------------------------
    // upload: success paths
    // ------------------------------------------------------------------

    public function testUploadSingleFileToRootStoresFileAndHashmapRow(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['hello.txt' => 'Hello upload']);

        $this->assertUploadSucceeded(['hello.txt'], $response);
        $this->assertSame(['/hello.txt' => 'Hello upload'], $this->disk());
        $this->assertSame(['/hello.txt' => $this->fileRow(12)], $this->rows());
    }

    public function testUploadRowOwnerIsActingUserAndAccessColumnsComeFromDefaultPermissions(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '5',
            'access_read' => 'RoleR',
            'access_update' => 'RoleU',
            'access_delete' => 'RoleD',
        ]]);

        $response = $this->apiUpload('/', ['hello.txt' => 'abc']);

        $this->assertUploadSucceeded(['hello.txt'], $response);
        // owner is the acting user (1), not the default owner (5)
        $this->assertSame(['/hello.txt' => $this->fileRow(3, '1', 'RoleR', 'RoleU', 'RoleD')], $this->rows());
    }

    public function testUploadToExistingSubfolder(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/sub');

        $response = $this->apiUpload('/sub', ['b.txt' => 'bb']);

        $this->assertUploadSucceeded(['sub/b.txt'], $response);
        $this->assertSame(['/sub' => null, '/sub/b.txt' => 'bb'], $this->disk());
        $this->assertSame(['/sub' => $this->dirRow(), '/sub/b.txt' => $this->fileRow(2)], $this->rows());
    }

    public function testUploadToSubfolderWithTrailingSlashBehavesLikeWithout(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/sub');

        $response = $this->apiUpload('/sub/', ['b.txt' => 'bb']);

        $this->assertUploadSucceeded(['sub/b.txt'], $response);
        $this->assertSame(['/sub' => null, '/sub/b.txt' => 'bb'], $this->disk());
    }

    public function testUploadSeveralFilesInOneRequest(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/sub');

        $response = $this->apiUpload('/sub', ['one.txt' => '1', 'two.txt' => '22', 'three.txt' => '333']);

        $this->assertUploadSucceeded(['sub/one.txt', 'sub/two.txt', 'sub/three.txt'], $response);
        $this->assertSame(
            ['/sub' => null, '/sub/one.txt' => '1', '/sub/three.txt' => '333', '/sub/two.txt' => '22'],
            $this->disk()
        );
        $this->assertSame([
            '/sub' => $this->dirRow(),
            '/sub/one.txt' => $this->fileRow(1),
            '/sub/three.txt' => $this->fileRow(3),
            '/sub/two.txt' => $this->fileRow(2),
        ], $this->rows());
    }

    public function testUploadToMissingFolderCreatesFolderAndSucceeds(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '5',
            'access_read' => 'RoleR',
            'access_update' => 'RoleU',
            'access_delete' => 'RoleD',
        ]]);

        $response = $this->apiUpload('/nofolder', ['c.txt' => 'C']);

        // NOTE: current behaviour, looks unintended: a missing destination is silently created, and its
        // hashmap row has no type (the row is repaired before the folder exists) and the DEFAULT owner
        $this->assertUploadSucceeded(['nofolder/c.txt'], $response);
        $this->assertSame(['/nofolder' => null, '/nofolder/c.txt' => 'C'], $this->disk());
        $this->assertSame([
            '/nofolder' => $this->row(null, null, '5', 'RoleR', 'RoleU', 'RoleD'),
            '/nofolder/c.txt' => $this->fileRow(1, '1', 'RoleR', 'RoleU', 'RoleD'),
        ], $this->rows());
    }

    public function testUploadToMissingNestedFolderCreatesAllFoldersButOnlyLeafGetsRow(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/new/deep', ['u.txt' => 'x']);

        // NOTE: current behaviour, looks unintended: no hashmap row for the intermediate folder `/new`
        $this->assertUploadSucceeded(['new/deep/u.txt'], $response);
        $this->assertSame(['/new' => null, '/new/deep' => null, '/new/deep/u.txt' => 'x'], $this->disk());
        $this->assertSame([
            '/new/deep' => $this->row(null, null),
            '/new/deep/u.txt' => $this->fileRow(1),
        ], $this->rows());
    }

    public function testUploadEmptyFileSucceedsWithSizeZero(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['empty.txt' => '']);

        $this->assertUploadSucceeded(['empty.txt'], $response);
        $this->assertSame(['/empty.txt' => ''], $this->disk());
        $this->assertSame(['/empty.txt' => $this->fileRow(0)], $this->rows());
    }

    public function testUploadWithoutAnyFileFailsWithEmptyError(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', []);

        // NOTE: current behaviour, looks unintended: HTTP 400 and success false without an error message
        $this->assertUploadFailed('', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadViaJsonPostWithoutDestinationFieldUploadsNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');

        $response = $this->apiPost('upload', ['path' => '/t']);

        $this->assertUploadFailed('', [], $response);
        $this->assertSame(['/t' => null], $this->disk());
    }

    // ------------------------------------------------------------------
    // upload: name slugging
    // ------------------------------------------------------------------

    public function testUploadSlugsSpacesAndUpperCaseAndLowerCasesExtension(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['My File.TXT' => 'x']);

        $this->assertUploadSucceeded(['my-file.txt'], $response);
        $this->assertSame(['/my-file.txt' => 'x'], $this->disk());
        $this->assertSame(['/my-file.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testUploadSlugsUmlautsAndRemovesDotsFromBaseName(): void
    {
        $this->actingAsAdmin();

        // depends on the transliteration of yii\helpers\Inflector::slug() (intl or the built-in table)
        $response = $this->apiUpload('/', ['Ärger Übel ß.tar.GZ' => 'z']);

        // NOTE: current behaviour, looks unintended: the dot of "ärger übel ß.tar" is dropped,
        // only the part after the LAST dot is kept as extension
        $this->assertUploadSucceeded(['arger-ubel-sstar.gz'], $response);
        $this->assertSame(['/arger-ubel-sstar.gz' => 'z'], $this->disk());
    }

    public function testUploadMultipleDotsKeepOnlyLastDotAsExtensionSeparator(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['Foo Bar.Baz.TXT' => 'q']);

        $this->assertUploadSucceeded(['foo-barbaz.txt'], $response);
        $this->assertSame(['/foo-barbaz.txt' => 'q'], $this->disk());
    }

    public function testUploadWithoutExtensionKeepsSlugOnly(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['No Ext' => 'n']);

        $this->assertUploadSucceeded(['no-ext'], $response);
        $this->assertSame(['/no-ext' => 'n'], $this->disk());
    }

    public function testUploadWithSlugNamesDisabledKeepsNameExactly(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['slugNames' => false]);

        $response = $this->apiUpload('/', ['My Fäile.TXT' => 'x', 'a.b.c.txt' => 'y']);

        $this->assertUploadSucceeded(['My Fäile.TXT', 'a.b.c.txt'], $response);
        $this->assertSame(['/My Fäile.TXT' => 'x', '/a.b.c.txt' => 'y'], $this->disk());
        $this->assertSame(['/My Fäile.TXT' => $this->fileRow(1), '/a.b.c.txt' => $this->fileRow(1)], $this->rows());
    }

    // ------------------------------------------------------------------
    // upload: collisions
    // ------------------------------------------------------------------

    public function testUploadOntoExistingFileWithSameContentKeepsOriginalAndReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');

        $response = $this->apiUpload('/', ['a.txt' => 'A']);

        $this->assertUploadFailed('File already exists', [], $response);
        $this->assertSame(['/a.txt' => 'A'], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testUploadOntoExistingFileWithDifferentContentKeepsOriginalAndReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'ORIGINAL');

        $response = $this->apiUpload('/', ['a.txt' => 'REPLACEMENT']);

        $this->assertUploadFailed('File already exists', [], $response);
        $this->assertSame(['/a.txt' => 'ORIGINAL'], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(8)], $this->rows());
    }

    public function testUploadOntoExistingFileInSubfolderKeepsOriginalAndReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/sub');
        $this->seedItem('/sub/a.txt', 'ORIGINAL');

        $response = $this->apiUpload('/sub', ['a.txt' => 'REPLACEMENT']);

        $this->assertUploadFailed('File already exists', [], $response);
        $this->assertSame(['/sub' => null, '/sub/a.txt' => 'ORIGINAL'], $this->disk());
        $this->assertSame(['/sub' => $this->dirRow(), '/sub/a.txt' => $this->fileRow(8)], $this->rows());
    }

    public function testUploadOntoExistingFileWithoutHashmapRowKeepsOriginalAndCreatesNoRow(): void
    {
        $this->actingAsAdmin();
        $this->seedFile('/a.txt', 'ORIGINAL');

        $response = $this->apiUpload('/', ['a.txt' => 'REPLACEMENT']);

        $this->assertUploadFailed('File already exists', [], $response);
        $this->assertSame(['/a.txt' => 'ORIGINAL'], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadOntoExistingDirectoryWithSameNameKeepsDirectoryAndReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/sub');
        $this->seedItem('/sub/inside.txt', 'I');

        $response = $this->apiUpload('/', ['sub' => 'D']);

        $this->assertUploadFailed('File already exists', [], $response);
        $this->assertSame(['/sub' => null, '/sub/inside.txt' => 'I'], $this->disk());
        $this->assertSame(['/sub' => $this->dirRow(), '/sub/inside.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testUploadOntoExistingNameAfterSlugKeepsOriginalAndReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/my-file.txt', 'ORIGINAL');

        $response = $this->apiUpload('/', ['My File.TXT' => 'REPLACEMENT']);

        $this->assertUploadFailed('File already exists', [], $response);
        $this->assertSame(['/my-file.txt' => 'ORIGINAL'], $this->disk());
        $this->assertSame(['/my-file.txt' => $this->fileRow(8)], $this->rows());
    }

    public function testUploadOntoStaleHashmapRowOfMissingFileRenewsTheRowForTheNewFile(): void
    {
        $this->actingAsAdmin();
        $this->seedHashmapRow('/u.txt', 'dir', ['access_owner' => '9', 'access_read' => 'ORPH'], 99);

        $response = $this->apiUpload('/', ['u.txt' => 'new']);

        // the stale row now describes the uploaded file, as if no row had existed
        $this->assertUploadSucceeded(['u.txt'], $response);
        $this->assertSame(['/u.txt' => 'new'], $this->disk());
        $this->assertSame(['/u.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testUploadAsUserOntoStaleRowRenewsItWithUserAsOwnerAndDefaultsAndKeepsOtherRows(): void
    {
        $this->actingAsFileflyUser(7);
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '1',
            'access_read' => 'RoleR',
            'access_update' => '*',
            'access_delete' => 'RoleD',
        ]]);
        $this->seedFolder('/d', ['access_owner' => '9', 'access_update' => '*']);
        $this->seedHashmapRow('/d/u.txt', 'file', [
            'access_owner' => '9',
            'access_read' => 'ORPH',
            'access_update' => 'ORPH',
            'access_delete' => 'ORPH',
        ], 99);
        $this->seedHashmapRow('/d/u.txt.bak', 'file', ['access_owner' => '9', 'access_read' => 'ORPH'], 99);
        $this->seedHashmapRow('/d/u.txt/x', 'file', ['access_owner' => '9', 'access_read' => 'ORPH'], 99);

        $response = $this->apiUpload('/d', ['u.txt' => 'new']);

        $this->assertUploadSucceeded(['d/u.txt'], $response);
        $this->assertSame(['/d' => null, '/d/u.txt' => 'new'], $this->disk());
        $this->assertSame([
            '/d' => $this->dirRow('9', 'RoleR', '*', 'RoleD'),
            '/d/u.txt' => $this->fileRow(3, '7', 'RoleR', '*', 'RoleD'),
            '/d/u.txt.bak' => $this->fileRow(99, '9', 'ORPH', '*', 'RoleD'),
            '/d/u.txt/x' => $this->fileRow(99, '9', 'ORPH', '*', 'RoleD'),
        ], $this->rows());
    }

    // ------------------------------------------------------------------
    // upload: partial failures in multi-file requests
    // ------------------------------------------------------------------

    public function testUploadPartialFailureSecondRejectedByExtensionKeepsFirstAndThird(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['allowedExtensions' => ['txt']]);

        $response = $this->apiUpload('/', ['ok.txt' => '1', 'bad.png' => '2', 'ok2.txt' => '3']);

        // NOTE: current behaviour, looks unintended: files are stored although the request answers 400/success false
        $this->assertUploadFailed('File type not allowed', ['ok.txt', 'ok2.txt'], $response);
        $this->assertSame(['/ok.txt' => '1', '/ok2.txt' => '3'], $this->disk());
        $this->assertSame(['/ok.txt' => $this->fileRow(1), '/ok2.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testUploadPartialFailureFirstCollidesSecondIsStored(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/ok.txt', 'ORIGINAL');

        $response = $this->apiUpload('/', ['ok.txt' => 'REPLACEMENT', 'z.txt' => '2']);

        $this->assertUploadFailed('File already exists', ['z.txt'], $response);
        $this->assertSame(['/ok.txt' => 'ORIGINAL', '/z.txt' => '2'], $this->disk());
        $this->assertSame(['/ok.txt' => $this->fileRow(8), '/z.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testUploadPartialFailureSecondCollidesFirstIsStored(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/ok.txt', 'ORIGINAL');

        $response = $this->apiUpload('/', ['n1.txt' => '1', 'ok.txt' => 'REPLACEMENT']);

        $this->assertUploadFailed('File already exists', ['n1.txt'], $response);
        $this->assertSame(['/n1.txt' => '1', '/ok.txt' => 'ORIGINAL'], $this->disk());
        $this->assertSame(['/n1.txt' => $this->fileRow(1), '/ok.txt' => $this->fileRow(8)], $this->rows());
    }

    public function testUploadSameNameTwiceInOneRequestStoresFirstAndRejectsSecond(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', [
            ['name' => 'd.txt', 'content' => 'FIRST'],
            ['name' => 'd.txt', 'content' => 'SECOND'],
        ]);

        $this->assertUploadFailed('File already exists', ['d.txt'], $response);
        $this->assertSame(['/d.txt' => 'FIRST'], $this->disk());
        $this->assertSame(['/d.txt' => $this->fileRow(5)], $this->rows());
    }

    public function testUploadTwoNamesWithSameSlugStoresFirstAndRejectsSecond(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', [
            ['name' => 'A B.txt', 'content' => 'FIRST'],
            ['name' => 'a-b.txt', 'content' => 'SECOND'],
        ]);

        $this->assertUploadFailed('File already exists', ['a-b.txt'], $response);
        $this->assertSame(['/a-b.txt' => 'FIRST'], $this->disk());
    }

    public function testUploadMultipleFailuresReportOnlyTheLastErrorMessage(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', [
            ['name' => 'big.txt', 'content' => 'x', 'error' => UPLOAD_ERR_INI_SIZE],
            ['name' => 'part.txt', 'content' => 'x', 'error' => UPLOAD_ERR_PARTIAL],
        ]);

        // NOTE: current behaviour, looks unintended: "File is too large" of the first file is overwritten
        $this->assertUploadFailed('Upload failed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    // ------------------------------------------------------------------
    // upload: validation through the API (nothing on disk, no row)
    // ------------------------------------------------------------------

    public function testUploadWithExtensionOutsideAllowedExtensionsIsRejected(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['allowedExtensions' => ['txt']]);

        $response = $this->apiUpload('/', ['x.bak' => '1']);

        $this->assertUploadFailed('File type not allowed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadWithDeniedExtensionIsRejectedAlsoInTheMiddleOfTheName(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deniedExtensions' => ['bak']]);

        $response = $this->apiUpload('/', ['x.bak.txt' => '1']);

        $this->assertUploadFailed('File type not allowed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadWithMultipleExtensionsIsRejectedWhenDenyMultipleExtensionsIsSet(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['denyMultipleExtensions' => true]);

        $response = $this->apiUpload('/', ['archive.tar.gz' => '1']);

        $this->assertUploadFailed('File names with multiple extensions are not allowed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadLargerThanMaxFileSizeIsRejectedAndSmallerOneStored(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['maxFileSize' => '5']);

        $response = $this->apiUpload('/', ['big.txt' => '123456', 'small.txt' => '123']);

        $this->assertUploadFailed('File is too large', ['small.txt'], $response);
        $this->assertSame(['/small.txt' => '123'], $this->disk());
        $this->assertSame(['/small.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testUploadExactlyMaxFileSizeIsAccepted(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['maxFileSize' => '5']);

        $response = $this->apiUpload('/', ['edge.txt' => '12345']);

        $this->assertUploadSucceeded(['edge.txt'], $response);
        $this->assertSame(['/edge.txt' => '12345'], $this->disk());
    }

    public function testUploadWithPhpContentIsRejectedByContentValidator(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['x.txt' => '<?php echo 1;']);

        $this->assertUploadFailed('File type not allowed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadOfPhpFileIsRejectedByDefault(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['x.php' => '<?php echo 1;']);

        $this->assertUploadFailed('File type not allowed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadOfHtmlFileIsAcceptedByDefault(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', ['x.html' => '<b>']);

        // NOTE: current behaviour, looks unintended: active content is only blocked when configured
        // (UploadValidator::DANGEROUS_EXTENSIONS is a recommendation, not the default)
        $this->assertUploadSucceeded(['x.html'], $response);
        $this->assertSame(['/x.html' => '<b>'], $this->disk());
    }

    public function testUploadWithMimeTypeOutsideMimeWhitelistSettingIsRejected(): void
    {
        $this->actingAsAdmin();
        $this->setSetting('mime-whitelist', 'image/png');

        $response = $this->apiUpload('/', ['w.txt' => 'plain text']);

        $this->assertUploadFailed('File type not allowed', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testUploadWithMimeTypeInsideMimeWhitelistSettingIsStored(): void
    {
        $this->actingAsAdmin();
        $this->setSetting('mime-whitelist', 'image/png,text/plain');

        $response = $this->apiUpload('/', ['w.txt' => 'plain text']);

        $this->assertUploadSucceeded(['w.txt'], $response);
        $this->assertSame(['/w.txt' => 'plain text'], $this->disk());
    }

    public function testUploadWithPhpUploadErrorIsRejectedAsTooLarge(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiUpload('/', [['name' => 'big.txt', 'content' => 'x', 'error' => UPLOAD_ERR_INI_SIZE]]);

        $this->assertUploadFailed('File is too large', [], $response);
        $this->assertSame([], $this->disk());
        $this->assertSame([], $this->rows());
    }

    public function testRejectedUploadToMissingFolderDoesNotCreateTheFolderOnDisk(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['allowedExtensions' => ['txt']]);

        $response = $this->apiUpload('/nofolder', ['x.bak' => '1']);

        $this->assertUploadFailed('File type not allowed', [], $response);
        $this->assertSame([], $this->disk());
        // NOTE: current behaviour, looks unintended: a row for the never created folder is left behind
        $this->assertSame(['/nofolder' => $this->row(null, null)], $this->rows());
    }

    // ------------------------------------------------------------------
    // upload: basic non-admin cases
    // ------------------------------------------------------------------

    public function testUploadAsUserToRootRecordsUserAsOwner(): void
    {
        $this->actingAsFileflyUser(7);

        $response = $this->apiUpload('/', ['u.txt' => '1']);

        $this->assertUploadSucceeded(['u.txt'], $response);
        $this->assertSame(['/u.txt' => '1'], $this->disk());
        $this->assertSame(['/u.txt' => $this->fileRow(1, '7')], $this->rows());
    }

    public function testUploadAsUserIntoFolderWithoutUpdateRightIsDeniedWith400(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedFolder('/locked', ['access_owner' => '9', 'access_update' => 'Nobody', 'access_delete' => 'Nobody']);

        $response = $this->apiUpload('/locked', ['u.txt' => '1']);

        $this->assertUploadFailed('You are not allowed to upload files to this directory', [], $response);
        $this->assertSame(['/locked' => null], $this->disk());
        $this->assertSame(['/locked' => $this->dirRow('9', '*', 'Nobody', 'Nobody')], $this->rows());
    }

    // ------------------------------------------------------------------
    // move: success paths
    // ------------------------------------------------------------------

    public function testMoveSingleFileIntoFolder(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedFolder('/t');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => null, '/t/a.txt' => 'AAA'], $this->disk());
        $this->assertSame(['/t' => $this->dirRow(), '/t/a.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testMoveKeepsAccessColumnsOfTheRow(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA', ['access_owner' => '7', 'access_read' => 'R', 'access_update' => 'U', 'access_delete' => 'D']);
        $this->seedFolder('/t');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => $this->dirRow(), '/t/a.txt' => $this->fileRow(3, '7', 'R', 'U', 'D')], $this->rows());
    }

    public function testMoveSeveralItemsIncludingDirectoryWithChildren(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BBB');
        $this->seedFolder('/d');
        $this->seedItem('/d/c.txt', 'CCC');
        $this->seedFolder('/d/e');
        $this->seedItem('/d/e/f.txt', 'FFF');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt', '/b.txt', '/d']]);

        $this->assertResult(true, '', $response);
        $this->assertSame([
            '/t' => null,
            '/t/a.txt' => 'AAA',
            '/t/b.txt' => 'BBB',
            '/t/d' => null,
            '/t/d/c.txt' => 'CCC',
            '/t/d/e' => null,
            '/t/d/e/f.txt' => 'FFF',
        ], $this->disk());
        $this->assertSame([
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(3),
            '/t/b.txt' => $this->fileRow(3),
            '/t/d' => $this->dirRow(),
            '/t/d/c.txt' => $this->fileRow(3),
            '/t/d/e' => $this->dirRow(),
            '/t/d/e/f.txt' => $this->fileRow(3),
        ], $this->rows());
    }

    public function testMoveDirectoryMovesChildRowsWithTheirAccessColumns(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');
        $this->seedFolder('/d', ['access_owner' => '7', 'access_read' => 'R']);
        $this->seedItem('/d/c.txt', 'C', ['access_owner' => '6', 'access_read' => 'CR']);

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/d']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => null, '/t/d' => null, '/t/d/c.txt' => 'C'], $this->disk());
        $this->assertSame([
            '/t' => $this->dirRow(),
            '/t/d' => $this->dirRow('7', 'R'),
            '/t/d/c.txt' => $this->fileRow(1, '6', 'CR'),
        ], $this->rows());
    }

    public function testMoveDirectoryWithoutChildRowsCreatesNoRowsForChildren(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/t');
        $this->seedDir('/d');
        $this->seedFile('/d/c.txt', 'C');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/d']]);

        // NOTE: current behaviour, looks unintended: only the directory itself is repaired, children stay without row
        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => null, '/t/d' => null, '/t/d/c.txt' => 'C'], $this->disk());
        $this->assertSame(['/t' => $this->dirRow(), '/t/d' => $this->dirRow()], $this->rows());
    }

    public function testMoveFileKeepsRowOfSiblingWithSamePathPrefix(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');
        $this->seedItem('/a.txt', 'A');
        $this->seedItem('/a.txt.bak', 'BAK', ['access_owner' => '8']);

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        // the row of /a.txt.bak stays where its file is
        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt.bak' => 'BAK', '/t' => null, '/t/a.txt' => 'A'], $this->disk());
        $this->assertSame([
            '/a.txt.bak' => $this->fileRow(3, '8'),
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(1),
        ], $this->rows());
    }

    public function testMoveDirectoryKeepsRowsOfSiblingDirectoryWithSamePathPrefix(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');
        $this->seedFolder('/d');
        $this->seedFolder('/dd', ['access_owner' => '8']);
        $this->seedItem('/dd/x.txt', 'X');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/d']]);

        // only the row of /d and the rows below "/d/" move
        $this->assertResult(true, '', $response);
        $this->assertSame(['/dd' => null, '/dd/x.txt' => 'X', '/t' => null, '/t/d' => null], $this->disk());
        $this->assertSame([
            '/dd' => $this->dirRow('8'),
            '/dd/x.txt' => $this->fileRow(1),
            '/t' => $this->dirRow(),
            '/t/d' => $this->dirRow(),
        ], $this->rows());
    }

    public function testMoveDirectoryWithLikeWildcardInNameKeepsRowsOfPathsMatchingTheWildcard(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');
        $this->seedFolder('/d_');
        $this->seedItem('/d_/x.txt', 'X');
        $this->seedFolder('/dz', ['access_owner' => '8']);
        $this->seedFolder('/dz/d_', ['access_owner' => '8']);
        $this->seedItem('/dz/d_/y.txt', 'Y', ['access_owner' => '8']);

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/d_']]);

        // "_" is matched literally: /dz and the "/d_" inside its child paths are left alone
        $this->assertResult(true, '', $response);
        $this->assertSame([
            '/dz' => null,
            '/dz/d_' => null,
            '/dz/d_/y.txt' => 'Y',
            '/t' => null,
            '/t/d_' => null,
            '/t/d_/x.txt' => 'X',
        ], $this->disk());
        $this->assertSame([
            '/dz' => $this->dirRow('8'),
            '/dz/d_' => $this->dirRow('8'),
            '/dz/d_/y.txt' => $this->fileRow(1, '8'),
            '/t' => $this->dirRow(),
            '/t/d_' => $this->dirRow(),
            '/t/d_/x.txt' => $this->fileRow(1),
        ], $this->rows());
    }

    public function testMoveRepairsMissingRowOfSourceBeforeMoving(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/t');
        $this->seedFile('/a.txt', 'A');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => null, '/t/a.txt' => 'A'], $this->disk());
        $this->assertSame(['/t' => $this->dirRow(), '/t/a.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testMoveWithRepairDisabledLeavesRowAtOldPathAndCreatesNoRowForNewPath(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['repair' => false]);
        $this->seedDir('/t');
        $this->seedFile('/a.txt', 'A');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        // NOTE: current behaviour, looks unintended: without a row SetAccess creates a typeless row for the OLD path
        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => null, '/t/a.txt' => 'A'], $this->disk());
        $this->assertSame(['/a.txt' => $this->row(null, null)], $this->rows());
    }

    public function testMoveToMissingTargetFolderCreatesFolderWithTypelessRow(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');

        $response = $this->apiPost('move', ['newPath' => '/nope', 'items' => ['/a.txt']]);

        // NOTE: current behaviour, looks unintended: the missing target is created silently, row without type
        $this->assertResult(true, '', $response);
        $this->assertSame(['/nope' => null, '/nope/a.txt' => 'AAA'], $this->disk());
        $this->assertSame(['/nope' => $this->row(null, null), '/nope/a.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testMoveToMissingNestedTargetFolderCreatesAllFolders(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');

        $response = $this->apiPost('move', ['newPath' => '/m/n', 'items' => ['/a.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/m' => null, '/m/n' => null, '/m/n/a.txt' => 'AAA'], $this->disk());
        $this->assertSame(['/m/n' => $this->row(null, null), '/m/n/a.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testMoveWithEmptyItemsListSucceedsNotAndDoesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => []]);

        $this->assertResult(false, '', $response);
        $this->assertSame(['/t' => null], $this->disk());
    }

    public function testMoveWithoutNewPathReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');

        $response = $this->apiPost('move', ['items' => ['/a.txt']]);

        $this->assertStatus(400, $response);
        $this->assertSame(['/a.txt' => 'A'], $this->disk());
    }

    // ------------------------------------------------------------------
    // move: collisions and impossible moves
    // ------------------------------------------------------------------

    public function testMoveFileOntoExistingFileInTargetAnswersFileAlreadyExistsAndKeepsBothFiles(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'SOURCE');
        $this->seedFolder('/t');
        $this->seedItem('/t/a.txt', 'TARGET');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        // nothing is overwritten, the refusal is the module's JSON error
        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'SOURCE', '/t' => null, '/t/a.txt' => 'TARGET'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(6),
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(6),
        ], $this->rows());
    }

    public function testMoveFileOntoExistingFileWithSameContentAnswersFileAlreadyExistsAndKeepsBothFiles(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'SAME');
        $this->seedFolder('/t');
        $this->seedItem('/t/a.txt', 'SAME');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'SAME', '/t' => null, '/t/a.txt' => 'SAME'], $this->disk());
    }

    public function testMoveFileOntoExistingDirectoryWithSameNameAnswersFileAlreadyExistsAndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/x', 'XFILE');
        $this->seedFolder('/t');
        $this->seedFolder('/t/x');
        $this->seedItem('/t/x/inside.txt', 'I');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/x']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/t' => null, '/t/x' => null, '/t/x/inside.txt' => 'I', '/x' => 'XFILE'], $this->disk());
        $this->assertSame([
            '/t' => $this->dirRow(),
            '/t/x' => $this->dirRow(),
            '/t/x/inside.txt' => $this->fileRow(1),
            '/x' => $this->fileRow(5),
        ], $this->rows());
    }

    public function testMoveDirectoryOntoExistingDirectoryWithSameNameAnswersFileAlreadyExistsAndMergesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/q');
        $this->seedItem('/q/z.txt', 'Z');
        $this->seedFolder('/t');
        $this->seedFolder('/t/q');
        $this->seedItem('/t/q/y.txt', 'Y');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/q']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame([
            '/q' => null,
            '/q/z.txt' => 'Z',
            '/t' => null,
            '/t/q' => null,
            '/t/q/y.txt' => 'Y',
        ], $this->disk());
        $this->assertSame([
            '/q' => $this->dirRow(),
            '/q/z.txt' => $this->fileRow(1),
            '/t' => $this->dirRow(),
            '/t/q' => $this->dirRow(),
            '/t/q/y.txt' => $this->fileRow(1),
        ], $this->rows());
    }

    public function testMoveDirectoryOntoExistingFileWithSameNameAnswersFileAlreadyExistsAndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/q');
        $this->seedItem('/q/z.txt', 'Z');
        $this->seedFolder('/t');
        $this->seedItem('/t/q', 'FILE');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/q']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/q' => null, '/q/z.txt' => 'Z', '/t' => null, '/t/q' => 'FILE'], $this->disk());
    }

    public function testMoveFileIntoItsOwnFolderAnswersFileAlreadyExistsAndKeepsFile(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/d');
        $this->seedItem('/d/c.txt', 'C');

        $response = $this->apiPost('move', ['newPath' => '/d', 'items' => ['/d/c.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/d' => null, '/d/c.txt' => 'C'], $this->disk());
        $this->assertSame(['/d' => $this->dirRow(), '/d/c.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testMoveRootFileToRootAnswersFileAlreadyExistsAndKeepsFile(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');

        $response = $this->apiPost('move', ['newPath' => '/', 'items' => ['/a.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'AAA'], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testMoveDirectoryIntoItselfFailsWith500AndChangesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/d');
        $this->seedItem('/d/c.txt', 'C');

        $response = $this->apiPost('move', ['newPath' => '/d', 'items' => ['/d']]);

        $this->assertStatus(500, $response);
        $this->assertSame(['/d' => null, '/d/c.txt' => 'C'], $this->disk());
        $this->assertSame(['/d' => $this->dirRow(), '/d/c.txt' => $this->fileRow(1)], $this->rows());
    }

    public function testMoveDirectoryIntoItsOwnSubdirectoryFailsWith500AndChangesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/d');
        $this->seedFolder('/d/s');
        $this->seedItem('/d/c.txt', 'C');

        $response = $this->apiPost('move', ['newPath' => '/d/s', 'items' => ['/d']]);

        $this->assertStatus(500, $response);
        $this->assertSame(['/d' => null, '/d/c.txt' => 'C', '/d/s' => null], $this->disk());
        $this->assertSame([
            '/d' => $this->dirRow(),
            '/d/c.txt' => $this->fileRow(1),
            '/d/s' => $this->dirRow(),
        ], $this->rows());
    }

    public function testMoveMissingSourceFailsWith500AndLeavesTypelessRowForTheMissingPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/d');

        $response = $this->apiPost('move', ['newPath' => '/d', 'items' => ['/missing.txt']]);

        // NOTE: current behaviour, looks unintended: the "notfound" branch of the action is never reached, and the
        // repair step leaves a typeless hashmap row for the path that does not exist
        $this->assertStatus(500, $response);
        $this->assertSame(['/d' => null], $this->disk());
        $this->assertSame(['/d' => $this->dirRow(), '/missing.txt' => $this->row(null, null)], $this->rows());
    }

    public function testMoveIntoExistingFileAsTargetFailsWith500AndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedItem('/f', 'F');

        $response = $this->apiPost('move', ['newPath' => '/f', 'items' => ['/a.txt']]);

        $this->assertStatus(500, $response);
        $this->assertSame(['/a.txt' => 'A', '/f' => 'F'], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(1), '/f' => $this->fileRow(1)], $this->rows());
    }

    public function testMoveOntoStaleHashmapRowOfMissingTargetKeepsStaleRowAndSourceRow(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/t');
        $this->seedFile('/a.txt', 'A');
        $this->seedHashmapRow('/t/a.txt', 'file', ['access_owner' => '9', 'access_read' => 'ORPH'], 99);

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt']]);

        // NOTE: current behaviour, looks unintended: the file moves, but renaming the row hits the unique index,
        // so the stale row is kept and the row of the old path stays; the response still reports success
        $this->assertResult(true, '', $response);
        $this->assertSame(['/t' => null, '/t/a.txt' => 'A'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(1),
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(99, '9', 'ORPH'),
        ], $this->rows());
    }

    public function testMoveOntoExistingItemFiresErrorEventAndNoAfterEventForTheCollidingItem(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedItem('/b.txt', 'B');
        $this->seedFolder('/t');
        $this->seedItem('/t/b.txt', 'T');
        $log = [];
        $this->recordEvents([
            FileEvent::EVENT_BEFORE_MOVE,
            FileEvent::EVENT_AFTER_MOVE,
            FileEvent::EVENT_MOVE_SUCCESS,
            FileEvent::EVENT_MOVE_ERROR,
        ], $log);

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt', '/b.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame([
            [FileEvent::EVENT_BEFORE_MOVE, '/a.txt', null],
            [FileEvent::EVENT_AFTER_MOVE, '/t/a.txt', null],
            [FileEvent::EVENT_BEFORE_MOVE, '/b.txt', null],
            [FileEvent::EVENT_MOVE_ERROR, null, 'file_already_exists'],
        ], $log);
    }

    // ------------------------------------------------------------------
    // move: mixed lists
    // ------------------------------------------------------------------

    public function testMoveMixedListStopsAtMissingItemWith500AndKeepsEarlierMoves(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BBB');
        $this->seedFolder('/d');

        $response = $this->apiPost('move', ['newPath' => '/d', 'items' => ['/a.txt', '/missing.txt', '/b.txt']]);

        // NOTE: current behaviour, looks unintended: a half-done move; a.txt moved, b.txt untouched
        $this->assertStatus(500, $response);
        $this->assertSame(['/b.txt' => 'BBB', '/d' => null, '/d/a.txt' => 'AAA'], $this->disk());
        $this->assertSame([
            '/b.txt' => $this->fileRow(3),
            '/d' => $this->dirRow(),
            '/d/a.txt' => $this->fileRow(3),
            '/missing.txt' => $this->row(null, null),
        ], $this->rows());
    }

    public function testMoveMixedListWithMissingItemFirstMovesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/b.txt', 'BBB');
        $this->seedFolder('/d');

        $response = $this->apiPost('move', ['newPath' => '/d', 'items' => ['/missing2', '/b.txt']]);

        $this->assertStatus(500, $response);
        $this->assertSame(['/b.txt' => 'BBB', '/d' => null], $this->disk());
    }

    public function testMoveMixedListStopsAtCollidingItemWithFileAlreadyExistsAndKeepsEarlierMoves(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BBB-SOURCE');
        $this->seedItem('/c.txt', 'CCC');
        $this->seedFolder('/t');
        $this->seedItem('/t/b.txt', 'BBB-TARGET');

        $response = $this->apiPost('move', ['newPath' => '/t', 'items' => ['/a.txt', '/b.txt', '/c.txt']]);

        // the items before the colliding one stay moved, the ones after it are not tried; the response reports failure
        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame([
            '/b.txt' => 'BBB-SOURCE',
            '/c.txt' => 'CCC',
            '/t' => null,
            '/t/a.txt' => 'AAA',
            '/t/b.txt' => 'BBB-TARGET',
        ], $this->disk());
        $this->assertSame([
            '/b.txt' => $this->fileRow(10),
            '/c.txt' => $this->fileRow(3),
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(3),
            '/t/b.txt' => $this->fileRow(10),
        ], $this->rows());
    }

    // ------------------------------------------------------------------
    // move: basic non-admin cases
    // ------------------------------------------------------------------

    public function testMoveAsUserIntoFolderWithoutUpdateRightIsDeniedWithNopermission(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedItem('/u.txt', 'U', ['access_owner' => '7']);
        $this->seedFolder('/locked', ['access_owner' => '9', 'access_update' => 'Nobody', 'access_delete' => 'Nobody']);

        $response = $this->apiPost('move', ['newPath' => '/locked', 'items' => ['/u.txt']]);

        $this->assertResult(false, 'nopermission', $response);
        $this->assertSame(['/locked' => null, '/u.txt' => 'U'], $this->disk());
        $this->assertSame([
            '/locked' => $this->dirRow('9', '*', 'Nobody', 'Nobody'),
            '/u.txt' => $this->fileRow(1, '7'),
        ], $this->rows());
    }

    public function testMoveAsUserWithoutAnyRightOnTheSourceIsDeniedEvenWhenTargetIsWritable(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedFolder('/d', ['access_owner' => '9']);
        $this->seedFolder('/locked', ['access_owner' => '9', 'access_update' => 'Nobody', 'access_delete' => 'Nobody']);
        $this->seedItem('/locked/f.txt', 'F', [
            'access_owner' => '9',
            'access_read' => 'Nobody',
            'access_update' => 'Nobody',
            'access_delete' => 'Nobody',
        ]);

        $response = $this->apiPost('move', ['newPath' => '/d', 'items' => ['/locked/f.txt']]);

        $this->assertResult(false, 'nopermission', $response);
        $this->assertSame(['/d' => null, '/locked' => null, '/locked/f.txt' => 'F'], $this->disk());
        $this->assertSame([
            '/d' => $this->dirRow('9'),
            '/locked' => $this->dirRow('9', '*', 'Nobody', 'Nobody'),
            '/locked/f.txt' => $this->fileRow(1, '9', 'Nobody', 'Nobody', 'Nobody'),
        ], $this->rows());
    }

    // ------------------------------------------------------------------
    // copy: success paths
    // ------------------------------------------------------------------

    public function testCopySingleFileKeepsNameAndSource(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedFolder('/dd');

        $response = $this->apiPost('copy', ['newPath' => '/dd', 'items' => ['/a.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'AAA', '/dd' => null, '/dd/a.txt' => 'AAA'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(3),
            '/dd' => $this->dirRow(),
            '/dd/a.txt' => $this->fileRow(3),
        ], $this->rows());
    }

    public function testCopySingleFileWithNewFilename(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedFolder('/dd');

        $response = $this->apiPost('copy', ['newPath' => '/dd', 'items' => ['/a.txt'], 'newFilename' => 'z.txt']);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'AAA', '/dd' => null, '/dd/z.txt' => 'AAA'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(3),
            '/dd' => $this->dirRow(),
            '/dd/z.txt' => $this->fileRow(3),
        ], $this->rows());
    }

    public function testCopyNewFilenameIsNotSlugged(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedFolder('/dd');

        $response = $this->apiPost('copy', ['newPath' => '/dd', 'items' => ['/a.txt'], 'newFilename' => 'My Copy.TXT']);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'AAA', '/dd' => null, '/dd/My Copy.TXT' => 'AAA'], $this->disk());
    }

    public function testCopySeveralFilesKeepsNames(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BB');
        $this->seedFolder('/dd');

        $response = $this->apiPost('copy', ['newPath' => '/dd', 'items' => ['/a.txt', '/b.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame([
            '/a.txt' => 'AAA',
            '/b.txt' => 'BB',
            '/dd' => null,
            '/dd/a.txt' => 'AAA',
            '/dd/b.txt' => 'BB',
        ], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(3),
            '/b.txt' => $this->fileRow(2),
            '/dd' => $this->dirRow(),
            '/dd/a.txt' => $this->fileRow(3),
            '/dd/b.txt' => $this->fileRow(2),
        ], $this->rows());
    }

    public function testCopyRowGetsActingUserAsOwnerAndDefaultPermissionsInsteadOfSourceAccess(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['defaultPermissions' => [
            'access_owner' => '5',
            'access_read' => 'RoleR',
            'access_update' => 'RoleU',
            'access_delete' => 'RoleD',
        ]]);
        $this->seedItem('/s.txt', 'S', ['access_owner' => '7', 'access_read' => 'SR', 'access_update' => 'SU', 'access_delete' => 'SD']);
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/s.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame([
            '/s.txt' => $this->fileRow(1, '7', 'SR', 'SU', 'SD'),
            '/t' => $this->dirRow('5', 'RoleR', 'RoleU', 'RoleD'),
            '/t/s.txt' => $this->fileRow(1, '1', 'RoleR', 'RoleU', 'RoleD'),
        ], $this->rows());
    }

    public function testCopyRepairsMissingRowOfSourceAndCreatesRowForCopy(): void
    {
        $this->actingAsAdmin();
        $this->seedDir('/t');
        $this->seedFile('/a.txt', 'A');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null, '/t/a.txt' => 'A'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(1),
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(1),
        ], $this->rows());
    }

    public function testCopyToMissingFolderCreatesFolderWithTypelessRow(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');

        $response = $this->apiPost('copy', ['newPath' => '/nope', 'items' => ['/a.txt']]);

        // NOTE: current behaviour, looks unintended: the missing target is created silently, row without type
        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'AAA', '/nope' => null, '/nope/a.txt' => 'AAA'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(3),
            '/nope' => $this->row(null, null),
            '/nope/a.txt' => $this->fileRow(3),
        ], $this->rows());
    }

    public function testCopyWithEmptyItemsListSucceedsNotAndDoesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => []]);

        $this->assertResult(false, '', $response);
        $this->assertSame(['/t' => null], $this->disk());
    }

    public function testCopyOntoStaleHashmapRowOfMissingTargetRenewsTheRowForTheCopy(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/e');
        $this->seedHashmapRow('/e/a.txt', 'dir', ['access_owner' => '9', 'access_read' => 'ORPH'], 99);
        $this->seedHashmapRow('/e/a.txt.bak', 'file', ['access_owner' => '9', 'access_read' => 'ORPH'], 99);

        $response = $this->apiPost('copy', ['newPath' => '/e', 'items' => ['/a.txt']]);

        // the stale row now describes the copy, as if no row had existed; the other rows stay
        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'A', '/e' => null, '/e/a.txt' => 'A'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(1),
            '/e' => $this->dirRow(),
            '/e/a.txt' => $this->fileRow(1),
            '/e/a.txt.bak' => $this->fileRow(99, '9', 'ORPH'),
        ], $this->rows());
    }

    public function testCopyWithNewFilenameAsUserOntoStaleRowRenewsItWithUserAsOwner(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedItem('/a.txt', 'AB', ['access_owner' => '7']);
        $this->seedFolder('/e', ['access_owner' => '9']);
        $this->seedHashmapRow('/e/z.txt', 'file', [
            'access_owner' => '9',
            'access_read' => 'ORPH',
            'access_update' => 'ORPH',
            'access_delete' => 'ORPH',
        ], 99);

        $response = $this->apiPost('copy', ['newPath' => '/e', 'items' => ['/a.txt'], 'newFilename' => 'z.txt']);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'AB', '/e' => null, '/e/z.txt' => 'AB'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(2, '7'),
            '/e' => $this->dirRow('9'),
            '/e/z.txt' => $this->fileRow(2, '7'),
        ], $this->rows());
    }

    // ------------------------------------------------------------------
    // copy: collisions and failures
    // ------------------------------------------------------------------

    public function testCopyOntoExistingFileAnswersFileAlreadyExistsAndKeepsBothContents(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'SOURCE');
        $this->seedFolder('/d');
        $this->seedItem('/d/a.txt', 'TARGET');

        $response = $this->apiPost('copy', ['newPath' => '/d', 'items' => ['/a.txt']]);

        // nothing is overwritten, the refusal is the module's JSON error
        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'SOURCE', '/d' => null, '/d/a.txt' => 'TARGET'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(6),
            '/d' => $this->dirRow(),
            '/d/a.txt' => $this->fileRow(6),
        ], $this->rows());
    }

    public function testCopyOntoExistingFileWithSameContentAnswersFileAlreadyExistsAndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'SAME');
        $this->seedFolder('/d');
        $this->seedItem('/d/a.txt', 'SAME');

        $response = $this->apiPost('copy', ['newPath' => '/d', 'items' => ['/a.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'SAME', '/d' => null, '/d/a.txt' => 'SAME'], $this->disk());
    }

    public function testCopyWithNewFilenameOntoExistingFileAnswersFileAlreadyExistsAndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'SOURCE');
        $this->seedFolder('/d');
        $this->seedItem('/d/other.txt', 'TARGET');

        $response = $this->apiPost('copy', ['newPath' => '/d', 'items' => ['/a.txt'], 'newFilename' => 'other.txt']);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'SOURCE', '/d' => null, '/d/other.txt' => 'TARGET'], $this->disk());
    }

    public function testCopyFileOntoExistingDirectoryWithSameNameAnswersFileAlreadyExistsAndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/b.txt', 'BBB');
        $this->seedFolder('/d');
        $this->seedFolder('/d/b.txt');
        $this->seedItem('/d/b.txt/inside.txt', 'I');

        $response = $this->apiPost('copy', ['newPath' => '/d', 'items' => ['/b.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame([
            '/b.txt' => 'BBB',
            '/d' => null,
            '/d/b.txt' => null,
            '/d/b.txt/inside.txt' => 'I',
        ], $this->disk());
        $this->assertSame([
            '/b.txt' => $this->fileRow(3),
            '/d' => $this->dirRow(),
            '/d/b.txt' => $this->dirRow(),
            '/d/b.txt/inside.txt' => $this->fileRow(1),
        ], $this->rows());
    }

    public function testCopyFileOntoItselfAnswersFileAlreadyExistsAndKeepsFile(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');

        $response = $this->apiPost('copy', ['newPath' => '/', 'items' => ['/a.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'AAA'], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(3)], $this->rows());
    }

    public function testCopySeveralFilesWithNewFilenameFailsOnSecondAndKeepsFirstCopy(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BBB');
        $this->seedFolder('/dd');

        $response = $this->apiPost('copy', ['newPath' => '/dd', 'items' => ['/a.txt', '/b.txt'], 'newFilename' => 'same.txt']);

        // NOTE: current behaviour, looks unintended: one new name for several sources collides with itself
        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'AAA', '/b.txt' => 'BBB', '/dd' => null, '/dd/same.txt' => 'AAA'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(3),
            '/b.txt' => $this->fileRow(3),
            '/dd' => $this->dirRow(),
            '/dd/same.txt' => $this->fileRow(3),
        ], $this->rows());
    }

    public function testCopyMixedListStopsAtCollidingItemWithFileAlreadyExistsAndKeepsEarlierCopies(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BBB-SOURCE');
        $this->seedItem('/c.txt', 'CCC');
        $this->seedFolder('/t');
        $this->seedItem('/t/b.txt', 'BBB-TARGET');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt', '/b.txt', '/c.txt']]);

        // the items before the colliding one stay copied, the ones after it are not tried; the response reports failure
        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame([
            '/a.txt' => 'AAA',
            '/b.txt' => 'BBB-SOURCE',
            '/c.txt' => 'CCC',
            '/t' => null,
            '/t/a.txt' => 'AAA',
            '/t/b.txt' => 'BBB-TARGET',
        ], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(3),
            '/b.txt' => $this->fileRow(10),
            '/c.txt' => $this->fileRow(3),
            '/t' => $this->dirRow(),
            '/t/a.txt' => $this->fileRow(3),
            '/t/b.txt' => $this->fileRow(10),
        ], $this->rows());
    }

    public function testCopyOntoExistingItemFiresErrorEventAndNoAfterEventForTheCollidingItem(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedItem('/b.txt', 'B');
        $this->seedFolder('/t');
        $this->seedItem('/t/b.txt', 'T');
        $log = [];
        $this->recordEvents([
            FileEvent::EVENT_BEFORE_COPY,
            FileEvent::EVENT_AFTER_COPY,
            FileEvent::EVENT_COPY_SUCCESS,
            FileEvent::EVENT_COPY_ERROR,
        ], $log);

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt', '/b.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame([
            [FileEvent::EVENT_BEFORE_COPY, '/a.txt', null],
            [FileEvent::EVENT_AFTER_COPY, '/a.txt', null],
            [FileEvent::EVENT_BEFORE_COPY, '/b.txt', null],
            [FileEvent::EVENT_COPY_ERROR, null, 'file_already_exists'],
        ], $log);
    }

    public function testCopyOntoExistingFileDoesNotRenewTheRowOfTheExistingTarget(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedItem('/a.txt', 'A', ['access_owner' => '7']);
        $this->seedFolder('/t', ['access_owner' => '9']);
        $this->seedItem('/t/a.txt', 'TARGET', ['access_owner' => '9', 'access_read' => 'R9']);

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt']]);

        $this->assertResult(false, 'File already exists', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null, '/t/a.txt' => 'TARGET'], $this->disk());
        $this->assertSame([
            '/a.txt' => $this->fileRow(1, '7'),
            '/t' => $this->dirRow('9'),
            '/t/a.txt' => $this->fileRow(6, '9', 'R9'),
        ], $this->rows());
    }

    public function testCopyDirectoryAnswers200WithEmptyBodyAndCopiesNothing(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/d');
        $this->seedItem('/d/c.txt', 'C');
        $this->seedFolder('/e');

        $response = $this->apiPost('copy', ['newPath' => '/e', 'items' => ['/d']]);

        // NOTE: current behaviour, looks unintended: the action returns false for a non-file, the response has no body
        $this->assertStatus(200, $response);
        $this->assertSame('', $response->body);
        $this->assertSame(['/d' => null, '/d/c.txt' => 'C', '/e' => null], $this->disk());
        $this->assertSame([
            '/d' => $this->dirRow(),
            '/d/c.txt' => $this->fileRow(1),
            '/e' => $this->dirRow(),
        ], $this->rows());
    }

    public function testCopyMixedListStopsAtDirectoryWithEmptyBodyAndKeepsEarlierCopy(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'AAA');
        $this->seedItem('/b.txt', 'BBB');
        $this->seedFolder('/d');
        $this->seedFolder('/e');

        $response = $this->apiPost('copy', ['newPath' => '/e', 'items' => ['/a.txt', '/d', '/b.txt']]);

        // NOTE: current behaviour, looks unintended: a.txt is copied, the directory aborts the loop, b.txt is skipped
        $this->assertStatus(200, $response);
        $this->assertSame('', $response->body);
        $this->assertSame([
            '/a.txt' => 'AAA',
            '/b.txt' => 'BBB',
            '/d' => null,
            '/e' => null,
            '/e/a.txt' => 'AAA',
        ], $this->disk());
        $this->assertArrayHasKey('/e/a.txt', $this->rows());
        $this->assertArrayNotHasKey('/e/b.txt', $this->rows());
    }

    public function testCopyMissingSourceFailsWith500AndLeavesTypelessRowForTheMissingPath(): void
    {
        $this->actingAsAdmin();
        $this->seedFolder('/e');

        $response = $this->apiPost('copy', ['newPath' => '/e', 'items' => ['/missing']]);

        $this->assertStatus(500, $response);
        $this->assertSame(['/e' => null], $this->disk());
        $this->assertSame(['/e' => $this->dirRow(), '/missing' => $this->row(null, null)], $this->rows());
    }

    public function testCopyIntoExistingFileAsTargetFailsWith500AndKeepsBoth(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedItem('/f', 'F');

        $response = $this->apiPost('copy', ['newPath' => '/f', 'items' => ['/a.txt']]);

        $this->assertStatus(500, $response);
        $this->assertSame(['/a.txt' => 'A', '/f' => 'F'], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(1), '/f' => $this->fileRow(1)], $this->rows());
    }

    public function testCopyWithoutNewPathReturns400(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');

        $response = $this->apiPost('copy', ['items' => ['/a.txt']]);

        $this->assertStatus(400, $response);
        $this->assertSame(['/a.txt' => 'A'], $this->disk());
    }

    // ------------------------------------------------------------------
    // copy: newFilename validation
    // ------------------------------------------------------------------

    public function testCopyWithEmptyNewFilenameIsRejectedAsInvalidName(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt'], 'newFilename' => '']);

        $this->assertResult(false, 'Invalid file name', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(1), '/t' => $this->dirRow()], $this->rows());
    }

    public function testCopyWithPathTraversalInNewFilenameIsRejectedAsInvalidName(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt'], 'newFilename' => '../x.txt']);

        $this->assertResult(false, 'Invalid file name', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null], $this->disk());
    }

    public function testCopyWithDeniedExtensionInNewFilenameIsRejected(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['deniedExtensions' => ['bak']]);
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt'], 'newFilename' => 'x.bak']);

        $this->assertResult(false, 'File type not allowed', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null], $this->disk());
        $this->assertSame(['/a.txt' => $this->fileRow(1), '/t' => $this->dirRow()], $this->rows());
    }

    public function testCopyWithNewFilenameOutsideAllowedExtensionsIsRejected(): void
    {
        $this->actingAsAdmin();
        $this->configureModule(['allowedExtensions' => ['txt']]);
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt'], 'newFilename' => 'x.png']);

        $this->assertResult(false, 'File type not allowed', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null], $this->disk());
    }

    public function testCopyToPhpFilenameIsAcceptedByDefault(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt'], 'newFilename' => 'n.php']);

        // NOTE: current behaviour, looks unintended: upload rejects PHP files by default, copy to a .php name does not
        // (only the name is validated, the content validator does not run for copy)
        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null, '/t/n.php' => 'A'], $this->disk());
    }

    public function testCopyToNewFilenameWithoutExtensionIsAcceptedByDefault(): void
    {
        $this->actingAsAdmin();
        $this->seedItem('/a.txt', 'A');
        $this->seedFolder('/t');

        $response = $this->apiPost('copy', ['newPath' => '/t', 'items' => ['/a.txt'], 'newFilename' => 'n2']);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/a.txt' => 'A', '/t' => null, '/t/n2' => 'A'], $this->disk());
    }

    // ------------------------------------------------------------------
    // copy: basic non-admin cases
    // ------------------------------------------------------------------

    public function testCopyAsUserIntoFolderWithoutUpdateRightIsDeniedWithNopermission(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedItem('/u.txt', 'U', ['access_owner' => '7']);
        $this->seedFolder('/locked', ['access_owner' => '9', 'access_update' => 'Nobody', 'access_delete' => 'Nobody']);

        $response = $this->apiPost('copy', ['newPath' => '/locked', 'items' => ['/u.txt']]);

        $this->assertResult(false, 'nopermission', $response);
        $this->assertSame(['/locked' => null, '/u.txt' => 'U'], $this->disk());
    }

    public function testCopyAsUserIntoWritableFolderMakesTheUserOwnerOfTheCopy(): void
    {
        $this->actingAsFileflyUser(7);
        $this->seedItem('/u.txt', 'U', ['access_owner' => '9']);
        $this->seedFolder('/d', ['access_owner' => '9']);

        $response = $this->apiPost('copy', ['newPath' => '/d', 'items' => ['/u.txt']]);

        $this->assertResult(true, '', $response);
        $this->assertSame(['/d' => null, '/d/u.txt' => 'U', '/u.txt' => 'U'], $this->disk());
        $this->assertSame([
            '/d' => $this->dirRow('9'),
            '/d/u.txt' => $this->fileRow(1, '7'),
            '/u.txt' => $this->fileRow(1, '9'),
        ], $this->rows());
    }
}
