<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use Aws\Exception\AwsException;
use creocoder\flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * What a scenario gets: the filesystem of one backend and its own, freshly
 * named scenario directory, plus helpers that turn results and exceptions into
 * short observation strings. Helpers never throw.
 */
final class Ctx
{
    public readonly FilesystemOperator $fs;

    public function __construct(
        public readonly Backend $backend,
        public readonly Filesystem $component,
        public readonly string $dir,
        public readonly string $localBasePath,
    ) {
        $this->fs = $component->getFilesystem();
    }

    public function p(string $relative = ''): string
    {
        return $relative === '' ? $this->dir : $this->dir . '/' . $relative;
    }

    public function isLocal(): bool
    {
        return $this->backend->kind === Backend::KIND_LOCAL;
    }

    public function isS3(): bool
    {
        return $this->backend->kind === Backend::KIND_S3;
    }

    public function isFtp(): bool
    {
        return $this->backend->kind === Backend::KIND_FTP;
    }

    /**
     * Runs $operation and describes the outcome: `ok` for void, the value for
     * scalars, `EXC Class: message` for an exception.
     */
    public function attempt(callable $operation): string
    {
        try {
            return self::describeValue($operation());
        } catch (Throwable $exception) {
            return self::describeException($exception);
        }
    }

    public function read(string $path, ?FilesystemOperator $fs = null): string
    {
        return $this->attempt(fn () => ($fs ?? $this->fs)->read($path));
    }

    /**
     * `fileExists/directoryExists/has` as `fe=.. de=.. has=..`.
     */
    public function exists(string $path, ?FilesystemOperator $fs = null): string
    {
        $fs ??= $this->fs;

        return sprintf(
            'fe=%s de=%s has=%s',
            $this->attempt(fn () => $fs->fileExists($path)),
            $this->attempt(fn () => $fs->directoryExists($path)),
            $this->attempt(fn () => $fs->has($path)),
        );
    }

    /**
     * Listing as `f:name d:name ...`, paths relative to $base when they start
     * with it, otherwise raw (then shown with the full path).
     */
    public function tree(string $base = '', bool $deep = true, ?FilesystemOperator $fs = null): string
    {
        $fs ??= $this->fs;
        try {
            $entries = [];
            foreach ($fs->listContents($base === '' ? $this->dir : $base, $deep) as $item) {
                $entries[] = ($item->isDir() ? 'd:' : 'f:') . $this->relative($item->path(), $base === '' ? $this->dir : $base);
            }

            return $entries === [] ? '(empty)' : implode(' ', $entries);
        } catch (Throwable $exception) {
            return self::describeException($exception);
        }
    }

    public function relative(string $path, string $base): string
    {
        $base = trim($base, '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path === $base && $base !== '' ? '.' : '=' . $path;
    }

    /**
     * Component of the same backend whose root is the scenario subdirectory
     * $relative (created first, FTP needs an existing root).
     */
    public function rooted(string $relative = ''): FilesystemOperator
    {
        $target = $this->p($relative);
        $this->fs->createDirectory($target);

        return $this->backend->buildRootedAt($target)->getFilesystem();
    }

    /**
     * Raw object keys below the scenario path (S3 only): shows directory
     * marker objects (`dir/`) that the Flysystem listing hides or shows.
     */
    public function rawKeys(string $relative = ''): string
    {
        if (!$this->component instanceof CountingAwsS3Filesystem) {
            return 'n/a';
        }
        try {
            $prefix = $this->p($relative) . '/';
            $result = $this->component->getClient()->listObjectsV2([
                'Bucket' => $this->component->bucket,
                'Prefix' => $prefix,
            ]);
            $keys = array_map(
                static fn (array $object) => substr($object['Key'], strlen($prefix)),
                $result['Contents'] ?? []
            );

            return $keys === [] ? '(none)' : implode(' ', $keys);
        } catch (Throwable $exception) {
            return self::describeException($exception);
        }
    }

    /**
     * S3 commands sent by $operation, as `HeadObject, GetObject (2)`; the
     * operation outcome is appended when it failed.
     */
    public function s3Commands(callable $operation): string
    {
        if (!$this->component instanceof CountingAwsS3Filesystem) {
            return 'n/a';
        }
        $outcome = 'ok';
        $commands = $this->component->recordCommands(function () use ($operation, &$outcome): void {
            $outcome = $this->attempt($operation);
        });
        $counted = [];
        foreach ($commands as $name) {
            $counted[$name] = ($counted[$name] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counted as $name => $count) {
            $parts[] = $count > 1 ? "$name x$count" : $name;
        }
        $summary = ($parts === [] ? '(none)' : implode(', ', $parts)) . ' = ' . count($commands);

        return str_starts_with($outcome, 'EXC') ? $summary . ' -> ' . $outcome : $summary;
    }

    public function localDiskPath(string $path): string
    {
        return rtrim($this->localBasePath, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param iterable<StorageAttributes> $listing
     */
    public static function attributes(StorageAttributes $item): string
    {
        $data = $item->jsonSerialize();
        $out = [];
        foreach ($data as $key => $value) {
            if ($key === StorageAttributes::ATTRIBUTE_EXTRA_METADATA) {
                $out[] = 'extra_metadata=' . ($value === [] ? '[]' : '[' . implode(',', array_keys($value)) . ']');
                continue;
            }
            if ($key === StorageAttributes::ATTRIBUTE_LAST_MODIFIED && is_int($value)) {
                $out[] = $key . '=' . self::timestamp($value);
                continue;
            }
            if ($key === StorageAttributes::ATTRIBUTE_PATH) {
                $value = self::markDir((string) $value);
            }
            $out[] = $key . '=' . ($value === null ? 'null' : self::describeValue($value));
        }

        return '{' . implode(', ', $out) . '}';
    }

    public static function timestamp(?int $value): string
    {
        if ($value === null) {
            return 'null';
        }

        return sprintf('%d (now%+ds)', $value, $value - time());
    }

    public static function describeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'ok',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::shorten(var_export($value, true), 80),
            is_resource($value) => 'resource(' . get_resource_type($value) . ')',
            is_array($value) => self::shorten(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'array', 200),
            is_object($value) => 'object(' . self::shortClass($value::class) . ')',
            default => gettype($value),
        };
    }

    public static function describeException(Throwable $exception): string
    {
        $text = 'EXC ' . self::describeOne($exception, 160);
        $previous = $exception->getPrevious();
        // Flysystem wraps once or twice (UnableToMoveFile <- UnableToCopyFile <- S3Exception)
        for ($depth = 0; $previous !== null && $depth < 2; $depth++) {
            if (str_starts_with($previous::class, 'GuzzleHttp\\')) {
                // the transport exception below an AwsException adds nothing
                break;
            }
            $text .= ' <- ' . self::describeOne($previous, 100);
            $previous = $previous->getPrevious();
        }

        return $text;
    }

    private static function describeOne(Throwable $exception, int $length): string
    {
        if ($exception instanceof AwsException) {
            // the SDK message repeats the full request URL; code and status are what matter
            return self::shortClass($exception::class) . ': ' . $exception->getAwsErrorCode()
                . ' HTTP ' . $exception->getStatusCode()
                . ($exception->getAwsErrorMessage() ? ' (' . self::shorten((string) $exception->getAwsErrorMessage(), 60) . ')' : '');
        }

        // Flysystem repeats the full SDK message; the AwsException in the chain says it shorter
        $message = (string) preg_replace('/\s*Error executing ".*$/s', '', $exception->getMessage());

        return self::shortClass($exception::class) . ': ' . self::shorten(self::redactPaths($message), $length);
    }

    /**
     * Strips the run and scenario directory from messages, so the cell shows
     * the reason instead of a long path: `.../probe-<run>/<backend>/<scenario>/a.txt`
     * becomes `a.txt`.
     */
    /**
     * Like redactPaths(), but keeps a visible `{dir}/` marker, so the form of
     * a path (leading or trailing slash) stays visible.
     */
    public static function markDir(string $text): string
    {
        return (string) preg_replace('#probe-\d{8}-\d{6}-[0-9a-f]{4}/[^/\s]+/[^/\s]+/#', '{dir}/', $text);
    }

    public static function redactPaths(string $text): string
    {
        return (string) preg_replace('#[^\s(\'"]*probe-\d{8}-\d{6}-[0-9a-f]{4}/[^/\s]+/[^/\s]+/?#', '', $text);
    }

    public static function shortClass(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    public static function shorten(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
    }
}
