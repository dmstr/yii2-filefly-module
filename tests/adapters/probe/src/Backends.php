<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use creocoder\flysystem\FtpFilesystem;
use creocoder\flysystem\LocalFilesystem;
use RuntimeException;

/**
 * The matrix columns. Every component is configured with the keys the phd5
 * application uses (config/common.php `fsLocal`, `fsS3`, `fsFtp`); hosts and
 * credentials come from ../test-credentials.env through the environment.
 */
final class Backends
{
    /**
     * @return list<Backend>
     */
    public static function fromEnvironment(string $localBasePath): array
    {
        $backends = [
            new Backend('local', Backend::KIND_LOCAL, 'LocalFilesystem, creocoder defaults (linkHandling DISALLOW_LINKS)', [
                'class' => LocalFilesystem::class,
                'path' => $localBasePath,
            ]),
            self::s3('s3-garage', 'Garage v2.5.0', 'http://garage:3900'),
            self::s3('s3-rustfs', 'RustFS 1.0.5', 'http://rustfs:9000'),
        ];

        foreach (['vsftpd' => 'vsftpd 3.0.5', 'proftpd' => 'ProFTPD 1.3.9e', 'pureftpd' => 'Pure-FTPd 1.0.54'] as $host => $label) {
            $backends[] = self::ftp('ftp-' . $host, $label . ', plain FTP, passive', $host, false);
            $backends[] = self::ftp('ftps-' . $host, $label . ', explicit FTPS (AUTH TLS), passive'
                . ($host === 'proftpd' ? ', server limited to TLS 1.2' : ''), $host, true);
        }
        $backends[] = self::ftp('ftps-proftpd-tls13', 'ProFTPD 1.3.9e, explicit FTPS with TLS 1.3 enabled and default session reuse requirement', 'proftpd-tls13', true);

        return $backends;
    }

    private static function s3(string $id, string $label, string $endpoint): Backend
    {
        return new Backend($id, Backend::KIND_S3, $label . ', path-style endpoint', [
            'class' => CountingAwsS3Filesystem::class,
            'key' => self::env('S3_KEY'),
            'secret' => self::env('S3_SECRET'),
            'bucket' => self::env('S3_BUCKET'),
            'prefix' => '',
            'region' => self::env('S3_REGION'),
            'endpoint' => $endpoint,
            'pathStyleEndpoint' => true,
        ]);
    }

    private static function ftp(string $id, string $label, string $host, bool $ssl): Backend
    {
        return new Backend($id, Backend::KIND_FTP, $label, [
            'class' => FtpFilesystem::class,
            'host' => $host,
            'port' => 21,
            'username' => self::env('FTP_USER'),
            'password' => self::env('FTP_PASSWORD'),
            'root' => '/',
            'ssl' => $ssl,
            'passive' => true,
            'timeout' => 10,
            'transferMode' => FTP_BINARY,
            'timestampsOnUnixListingsEnabled' => true,
        ]);
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException("Environment variable $name is missing, see test-credentials.env");
        }

        return $value;
    }
}
