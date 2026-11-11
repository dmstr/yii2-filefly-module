<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\support;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use creocoder\flysystem\AwsS3Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * creocoder's AwsS3Filesystem with an AWS SDK middleware that records the name of every S3
 * command attempt (one entry per HTTP request, retries included), the idea of the probe's
 * `./probe/src/CountingAwsS3Filesystem.php`.
 *
 * creocoder 2.0 builds the S3Client inside prepareAdapter() and does not expose it, so the
 * parent builds the adapter unchanged and the client is read back from the adapter's private
 * `client` property. The component config is exactly the one of an application.
 *
 * The log is static: the API harness builds a fresh component for every request, and a test
 * reads the requests of all of them.
 */
class CountingAwsS3Filesystem extends AwsS3Filesystem
{
    /**
     * @var string[] S3 command names in the order they were sent
     */
    public static $log = [];

    /**
     * @var S3Client|null
     */
    private $client;

    protected function prepareAdapter()
    {
        $adapter = parent::prepareAdapter();
        $property = new \ReflectionProperty(AwsS3V3Adapter::class, 'client');
        $property->setAccessible(true);
        $this->client = $property->getValue($adapter);
        $this->client->getHandlerList()->appendAttempt(
            Middleware::tap(static function (CommandInterface $command) {
                self::$log[] = $command->getName();
            }),
            'filefly-contract-command-counter'
        );
        return $adapter;
    }

    /**
     * The S3 client of this component, for seeding and asserting raw keys
     */
    public function getClient(): S3Client
    {
        return $this->client;
    }

    /**
     * Runs $operation and returns the S3 commands it sent. Its return value goes to $result, an
     * exception it throws to $exception (and is not passed on).
     *
     * @return string[]
     */
    public static function record(callable $operation, &$result = null, ?\Throwable &$exception = null): array
    {
        self::$log = [];
        $result = null;
        $exception = null;
        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $exception = $e;
        }
        $log = self::$log;
        self::$log = [];
        return $log;
    }
}
