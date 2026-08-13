<?php
/**
 * Tests für die Flysystem-Mechanik hinter ApiController::actionStream()
 * und ApiController::actionDownload() (siehe Basecamp-Ticket: S3-HEAD-Requests
 * pro Auslieferung reduzieren).
 *
 * Diese Tests laufen gegen einen lokalen Flysystem-Adapter statt gegen S3,
 * um unabhängig von echten Cloud-Credentials verifizieren zu können, dass:
 *   1. getMetadata() die für den Fix benötigten Felder liefert (type, size)
 *   2. readStream() auf Adapter-Ebene ein Array liefert, aus dem der
 *      Stream unter dem Schlüssel 'stream' ausgepackt werden muss
 *   3. das bisherige 404-Verhalten bei fehlenden Dateien erhalten bleibt
 *   4. die Mimetype-Ermittlung per Dateiendung (ohne zusätzlichen Request)
 *      sinnvolle Werte liefert
 */

namespace hrzg\filefly\tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\Util\MimeType;
use PHPUnit\Framework\TestCase;

class StreamMetadataTest extends TestCase
{
    private string $testDir;
    private $adapter;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/filefly-test-' . uniqid();
        mkdir($this->testDir);
        file_put_contents($this->testDir . '/hello.txt', 'Hello Filefly Test');

        $filesystem = new Filesystem(new Local($this->testDir));
        $this->adapter = $filesystem->getAdapter();
    }

    protected function tearDown(): void
    {
        @unlink($this->testDir . '/hello.txt');
        @rmdir($this->testDir);
    }

    /**
     * Ein einzelner getMetadata()-Aufruf muss type und size liefern -
     * genau das, was actionStream()/actionDownload() statt der drei
     * separaten Aufrufe (isFile(), getMimetype(), getSize()) nutzt.
     */
    public function testGetMetadataReturnsTypeAndSize(): void
    {
        $metadata = $this->adapter->getMetadata('hello.txt');

        $this->assertIsArray($metadata);
        $this->assertSame('file', $metadata['type']);
        $this->assertSame(18, $metadata['size']); // "Hello Filefly Test" = 18 Zeichen
    }

    /**
     * Kritischer Fix-Punkt: adapter->readStream() liefert auf Adapter-Ebene
     * ein Array ['type' => ..., 'stream' => resource], NICHT direkt die
     * Stream-Resource. Wird das nicht beachtet, bricht der Download.
     */
    public function testReadStreamMustBeUnwrappedFromArray(): void
    {
        $streamResult = $this->adapter->readStream('hello.txt');

        $this->assertIsArray($streamResult, 'readStream() auf Adapter-Ebene muss ein Array liefern');
        $this->assertArrayHasKey('stream', $streamResult);
        $this->assertIsResource($streamResult['stream']);

        $content = stream_get_contents($streamResult['stream']);
        fclose($streamResult['stream']);

        $this->assertSame('Hello Filefly Test', $content);
    }

    /**
     * 404-Verhalten: Bei fehlender Datei muss der Code (via äußerem
     * catch(Exception)) weiterhin auf einen "nicht gefunden"-Fall
     * abbilden können. Der Local-Adapter wirft dafür eine Exception
     * statt false zurückzugeben - unser Code muss beide Fälle abdecken.
     */
    public function testMissingFileTriggersExceptionOrFalse(): void
    {
        $threwException = false;
        $result = null;

        try {
            $result = $this->adapter->getMetadata('does-not-exist.txt');
        } catch (\Throwable $e) {
            $threwException = true;
        }

        $this->assertTrue(
            $threwException || $result === false,
            'Fehlende Datei muss entweder eine Exception werfen oder false liefern, ' .
            'damit der Controller korrekt auf NotFoundHttpException abbilden kann'
        );
    }

    /**
     * Mimetype-Fallback per Dateiendung (kein zusätzlicher S3-Request):
     * League\Flysystem\Util\MimeType::detectByFilename() muss sinnvolle
     * Werte für gängige Dateitypen liefern.
     */
    public function testMimeTypeDetectionByFilename(): void
    {
        $this->assertSame('text/plain', MimeType::detectByFilename('hello.txt'));
        $this->assertSame('image/jpeg', MimeType::detectByFilename('photo.jpg'));
        $this->assertSame('application/pdf', MimeType::detectByFilename('document.pdf'));
    }

    /**
     * getMetadata() liefert bei diesem Adapter kein 'mimetype'-Feld -
     * bestätigt, dass der Fallback in actionStream()/actionDownload()
     * tatsächlich greifen wird (Regressions-Absicherung: falls eine
     * künftige Flysystem-Version das Feld doch mitliefert, soll dieser
     * Test auffallen und wir können den Fallback ggf. entfernen).
     */
    public function testGetMetadataDoesNotIncludeMimetype(): void
    {
        $metadata = $this->adapter->getMetadata('hello.txt');

        $this->assertArrayNotHasKey('mimetype', $metadata);
    }
}