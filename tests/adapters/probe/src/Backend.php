<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use creocoder\flysystem\Filesystem;
use Yii;

/**
 * One column of the matrix: a creocoder component config plus the key that
 * re-roots it (local `path`, S3 `prefix`, FTP `root`).
 */
final class Backend
{
    public const KIND_LOCAL = 'local';
    public const KIND_S3 = 's3';
    public const KIND_FTP = 'ftp';

    /**
     * @param array<string, mixed> $config creocoder component config
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $description,
        public readonly array $config,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function build(array $overrides = []): Filesystem
    {
        return Yii::createObject(array_merge($this->config, $overrides));
    }

    /**
     * Component whose root is $relative below this backend's root.
     */
    public function buildRootedAt(string $relative): Filesystem
    {
        $relative = trim($relative, '/');

        return match ($this->kind) {
            self::KIND_LOCAL => $this->build(['path' => rtrim($this->config['path'], '/') . '/' . $relative]),
            self::KIND_S3 => $this->build(['prefix' => trim(($this->config['prefix'] ?? '') . '/' . $relative, '/')]),
            self::KIND_FTP => $this->build(['root' => rtrim($this->config['root'], '/') . '/' . $relative]),
        };
    }

    /**
     * Config as shown in the report, password and secret masked.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $config = $this->config;
        foreach (['password', 'secret'] as $key) {
            if (isset($config[$key])) {
                $config[$key] = '***';
            }
        }

        return $config;
    }
}
