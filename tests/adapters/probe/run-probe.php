<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

/*
 * Runs the adapter probe against every backend of ../compose.yaml and writes
 * ../results.json and ../RESULTS.md. Meant to run in the `probe` container:
 *
 *   docker compose run --rm probe
 *
 * Options (a filtered run prints JSON to stdout and leaves the files alone):
 *   --backend=local,s3-garage   only these columns
 *   --scenario=move.,s3req.     only rows whose id starts with one of these
 */

declare(strict_types=1);

use hrzg\filefly\adapterprobe\Backends;
use hrzg\filefly\adapterprobe\Probe;
use hrzg\filefly\adapterprobe\Report;
use hrzg\filefly\adapterprobe\Scenarios;

ini_set('display_errors', 'stderr');

$vendor = getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/vendor';
require $vendor . '/autoload.php';
require $vendor . '/yiisoft/yii2/Yii.php';

$options = getopt('', ['backend:', 'scenario:']);
$filter = static fn (string $name): array => isset($options[$name]) ? explode(',', (string) $options[$name]) : [];
$onlyBackends = $filter('backend');
$onlyScenarios = $filter('scenario');

$localBasePath = sys_get_temp_dir() . '/filefly-probe-local';
$backends = array_values(array_filter(
    Backends::fromEnvironment($localBasePath),
    static fn ($backend) => $onlyBackends === [] || in_array($backend->id, $onlyBackends, true)
));
$scenarios = array_values(array_filter(
    Scenarios::all(),
    static function ($scenario) use ($onlyScenarios) {
        foreach ($onlyScenarios as $prefix) {
            if (str_starts_with($scenario->id, $prefix)) {
                return true;
            }
        }

        return $onlyScenarios === [];
    }
));

$result = (new Probe($backends, $scenarios, $localBasePath))->run();
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($onlyBackends !== [] || $onlyScenarios !== []) {
    echo $json, "\n";
    exit(0);
}

$adaptersDir = dirname(__DIR__);
file_put_contents($adaptersDir . '/results.json', $json . "\n");
file_put_contents(
    $adaptersDir . '/RESULTS.md',
    Report::markdown($result, (string) file_get_contents(__DIR__ . '/migration-notes.md'))
);
fwrite(STDERR, "Wrote ./results.json and ./RESULTS.md\n");
