<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use creocoder\flysystem\AwsS3Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use ReflectionProperty;

/**
 * creocoder's AwsS3Filesystem with an AWS SDK middleware that records the name
 * of every S3 command attempt (one entry per HTTP request, retries included).
 *
 * creocoder 2.0 builds the S3Client inside prepareAdapter() and does not
 * expose it, so this subclass lets the parent build the adapter unchanged and
 * reads the client back from the adapter's private `client` property. No
 * option mapping is copied; the component config is exactly the phd5 one.
 */
class CountingAwsS3Filesystem extends AwsS3Filesystem
{
    /** @var list<string> */
    private array $commandLog = [];

    private ?S3Client $client = null;

    protected function prepareAdapter()
    {
        $adapter = parent::prepareAdapter();
        $property = new ReflectionProperty(AwsS3V3Adapter::class, 'client');
        $this->client = $property->getValue($adapter);
        $this->client->getHandlerList()->appendAttempt(
            Middleware::tap(function (CommandInterface $command): void {
                $this->commandLog[] = $command->getName();
            }),
            'filefly-probe-command-counter'
        );

        return $adapter;
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }

    /**
     * Runs $operation and returns the S3 commands it sent.
     *
     * @return list<string>
     */
    public function recordCommands(callable $operation): array
    {
        $this->commandLog = [];
        $operation();

        return $this->commandLog;
    }
}
