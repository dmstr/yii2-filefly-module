<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\support;

use Aws\S3\S3Client;
use League\Flysystem\FilesystemOperator;

/**
 * A fresh, unique root on a remote backend for one test: the component config rooted there and
 * the removal of everything below it.
 *
 * - S3: the prefix `filefly-contract/<unique>`; remove() deletes every key that starts with it,
 *   also sibling keys `<unique>.txt`, `<unique>-outside/...` that a test seeds outside the prefix
 * - FTP: the directory `/filefly-contract/<unique>` below the FTP user's home, created through a
 *   second component rooted at the home and removed through it
 */
final class TestRoot
{
    /** @var array creocoder component config rooted at the test root */
    public $config;

    /** @var string|null S3 prefix of the root, without trailing slash */
    public $prefix;

    /** @var S3Client|null S3 client, for raw keys */
    public $client;

    /** @var FilesystemOperator|null FTP operator rooted at the home */
    private $home;

    /** @var string|null FTP root relative to the home */
    private $relativeRoot;

    public static function s3(string $endpoint): self
    {
        Backends::requireStack();
        $root = new self();
        $root->prefix = Backends::S3_BASE_PREFIX . '/' . Backends::uniqueName('c');
        $root->config = Backends::s3($endpoint, $root->prefix);
        /** @var CountingAwsS3Filesystem $component */
        $component = \Yii::createObject($root->config);
        $root->client = $component->getClient();
        Backends::ensureBucket($root->client, Backends::env('S3_BUCKET'));
        return $root;
    }

    public static function ftp(string $host, bool $ssl): self
    {
        Backends::requireStack();
        $root = new self();
        $root->home = \Yii::createObject(Backends::ftp($host, $ssl, '/'))->getFilesystem();
        $root->relativeRoot = ltrim(Backends::FTP_BASE_ROOT, '/') . '/' . Backends::uniqueName('c');
        $root->home->createDirectory($root->relativeRoot);
        $root->config = Backends::ftp($host, $ssl, '/' . $root->relativeRoot);
        return $root;
    }

    public function remove(): void
    {
        if ($this->client !== null && strpos((string)$this->prefix, Backends::S3_BASE_PREFIX . '/c-') === 0) {
            $this->client->deleteMatchingObjects(Backends::env('S3_BUCKET'), $this->prefix);
        }
        if ($this->home !== null && strpos((string)$this->relativeRoot, ltrim(Backends::FTP_BASE_ROOT, '/') . '/c-') === 0) {
            $this->home->deleteDirectory($this->relativeRoot);
        }
        $this->client = null;
        $this->home = null;
    }
}
