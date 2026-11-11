<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use Composer\InstalledVersions;
use RuntimeException;
use Throwable;

/**
 * Runs every scenario against every backend. Each scenario gets a fresh
 * component (fresh FTP connection, fresh S3 client) and its own directory
 * below a run directory that is unique per run.
 */
final class Probe
{
    private const PACKAGES = [
        'league/flysystem',
        'league/flysystem-local',
        'league/flysystem-aws-s3-v3',
        'league/flysystem-ftp',
        'league/mime-type-detection',
        'aws/aws-sdk-php',
        'creocoder/yii2-flysystem',
        'yiisoft/yii2',
    ];

    /**
     * @param list<Backend> $backends
     * @param list<Scenario> $scenarios
     */
    public function __construct(
        private readonly array $backends,
        private readonly array $scenarios,
        private readonly string $localBasePath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $runId = 'probe-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $rows = [];
        foreach ($this->scenarios as $scenario) {
            $rows[$scenario->id] = [
                'id' => $scenario->id,
                'group' => $scenario->group,
                'title' => $scenario->title,
                'results' => [],
            ];
        }
        $backendInfo = [];
        foreach ($this->backends as $backend) {
            $started = microtime(true);
            $error = $this->prepare($backend, $runId);
            fwrite(STDERR, sprintf("%-15s %s\n", $backend->id, $error ?? 'ready'));
            foreach ($this->scenarios as $scenario) {
                $rows[$scenario->id]['results'][$backend->id] = match (true) {
                    !$scenario->appliesTo($backend) => null,
                    $error !== null => ['backend' => 'NOT AVAILABLE: ' . $error],
                    default => $this->runScenario($scenario, $backend, $runId),
                };
            }
            $backendInfo[$backend->id] = [
                'kind' => $backend->kind,
                'description' => $backend->description,
                'config' => $backend->publicConfig(),
                'available' => $error === null,
                'error' => $error,
                'seconds' => round(microtime(true) - $started, 1),
            ];
        }

        return [
            'generated' => gmdate('Y-m-d H:i:s') . ' UTC',
            'runId' => $runId,
            'php' => PHP_VERSION,
            'packages' => array_combine(self::PACKAGES, array_map(
                static fn (string $package) => InstalledVersions::getPrettyVersion($package),
                self::PACKAGES
            )),
            'backends' => $backendInfo,
            'scenarios' => array_values($rows),
        ];
    }

    /**
     * Waits until the backend answers (servers may still be starting) and
     * creates what the run needs. Returns an error text or null.
     */
    private function prepare(Backend $backend, string $runId): ?string
    {
        if ($backend->kind === Backend::KIND_LOCAL && !is_dir($this->localBasePath)) {
            mkdir($this->localBasePath, 0777, true);
        }
        $deadline = time() + 30;
        do {
            try {
                $component = $backend->build();
                if ($component instanceof CountingAwsS3Filesystem) {
                    $client = $component->getClient();
                    if (!$client->doesBucketExistV2($component->bucket, false)) {
                        // Garage creates its bucket at start, RustFS needs it created here
                        $client->createBucket(['Bucket' => $component->bucket]);
                    }
                }
                $component->getFilesystem()->createDirectory($runId . '/' . $backend->id);

                return null;
            } catch (Throwable $exception) {
                $last = Ctx::describeException($exception);
                sleep(1);
            }
        } while (time() < $deadline);

        return $last ?? 'unknown error';
    }

    /**
     * @return array<string, string>
     */
    private function runScenario(Scenario $scenario, Backend $backend, string $runId): array
    {
        // per backend: plain and FTPS columns share one server
        $dir = $runId . '/' . $backend->id . '/' . preg_replace('/[^a-z0-9.-]+/i', '-', $scenario->id);
        $warnings = [];
        // Record PHP warnings that are NOT silenced with @: a Yii application
        // turns exactly these into an ErrorException.
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            if ((error_reporting() & $level) !== 0) {
                $warnings[] = Ctx::shorten(Ctx::redactPaths($message), 140);
            }

            return true;
        });
        try {
            $ctx = new Ctx($backend, $backend->build(), $dir, $this->localBasePath);
            $observations = ($scenario->run)($ctx);
            if (!is_array($observations)) {
                throw new RuntimeException('scenario returned ' . get_debug_type($observations));
            }
            unset($ctx);
            gc_collect_cycles();
        } catch (Throwable $exception) {
            $observations = ['scenario setup' => 'FAILED ' . Ctx::describeException($exception)];
        } finally {
            restore_error_handler();
        }
        if ($warnings !== []) {
            $observations['PHP warnings (not @-silenced)'] = count($warnings) . 'x, first: ' . $warnings[0];
        }

        return $observations;
    }
}
