<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\support;

use Aws\S3\S3Client;
use creocoder\flysystem\FtpFilesystem;
use creocoder\flysystem\LocalFilesystem;
use PHPUnit\Framework\SkippedTestError;

/**
 * Component configurations of the adapter suite, with the config keys an application uses (see
 * `UPGRADE.md`), and the environment of the compose stack (`../test-credentials.env`).
 *
 * Hosts are the compose service names; outside the compose network the remote backends are
 * skipped (FILEFLY_ADAPTER_STACK is set only by the compose service, so a missing server inside
 * the stack fails instead of being skipped).
 */
final class Backends
{
    /** base prefix of every S3 test prefix */
    public const S3_BASE_PREFIX = 'filefly-contract';

    /** base directory of every FTP test root, below the FTP user's home */
    public const FTP_BASE_ROOT = '/filefly-contract';

    /**
     * Local component as UPGRADE.md recommends it
     */
    public static function local(string $path): array
    {
        return [
            'class' => LocalFilesystem::class,
            'path' => $path,
            'config' => ['directory_visibility' => 'public'],
        ];
    }

    /**
     * S3 component (path-style endpoint, as for S3-compatible servers), with request counting
     */
    public static function s3(string $endpoint, string $prefix): array
    {
        return [
            'class' => CountingAwsS3Filesystem::class,
            'key' => self::env('S3_KEY'),
            'secret' => self::env('S3_SECRET'),
            'bucket' => self::env('S3_BUCKET'),
            'prefix' => $prefix,
            'region' => self::env('S3_REGION'),
            'endpoint' => $endpoint,
            'pathStyleEndpoint' => true,
        ];
    }

    /**
     * FTP component, plain or explicit FTPS, passive, timestamps in listings enabled
     */
    public static function ftp(string $host, bool $ssl, string $root): array
    {
        return [
            'class' => FtpFilesystem::class,
            'host' => $host,
            'port' => 21,
            'username' => self::env('FTP_USER'),
            'password' => self::env('FTP_PASSWORD'),
            'root' => $root,
            'ssl' => $ssl,
            'passive' => true,
            'timeout' => 10,
            'transferMode' => FTP_BINARY,
            'timestampsOnUnixListingsEnabled' => true,
        ];
    }

    /**
     * Skips the calling test outside the compose stack
     */
    public static function requireStack(): void
    {
        if (getenv('FILEFLY_ADAPTER_STACK') !== '1') {
            throw new SkippedTestError('needs the compose stack of tests/adapters (see its README.md)');
        }
    }

    /**
     * Creates the bucket if the server does not create it itself (RustFS)
     */
    public static function ensureBucket(S3Client $client, string $bucket): void
    {
        static $checked = [];
        $endpoint = (string)$client->getEndpoint();
        if (isset($checked[$endpoint])) {
            return;
        }
        if (!$client->doesBucketExistV2($bucket, false)) {
            $client->createBucket(['Bucket' => $bucket]);
        }
        $checked[$endpoint] = true;
    }

    public static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Environment variable $name is missing, see tests/adapters/test-credentials.env");
        }
        return $value;
    }

    /**
     * A fresh unique name for a test root
     */
    public static function uniqueName(string $prefix): string
    {
        return $prefix . '-' . date('His') . '-' . bin2hex(random_bytes(5));
    }

    /**
     * Removes a local directory tree that this suite created below the temp directory
     */
    public static function removeLocalTree(string $dir, string $requiredPrefix): void
    {
        $base = realpath(sys_get_temp_dir());
        if (strpos($dir, $base . '/' . $requiredPrefix) !== 0 || !is_dir($dir) || is_link($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
