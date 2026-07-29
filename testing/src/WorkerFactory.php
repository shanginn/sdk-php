<?php

declare(strict_types=1);

namespace Temporal\Testing;

use Temporal\Client\WorkflowClient;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Plugin\PluginRegistry;
use Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface;
use Temporal\Worker\ActivityInvocationCache\FileActivityInvocationCache;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerInterface;
use TrueAsync\Temporal\Core\Connection;

/**
 * Native worker factory with process-safe activity mocking.
 */
class WorkerFactory extends \Temporal\WorkerFactory
{
    private ActivityInvocationCacheInterface $activityCache;

    public function __construct(
        DataConverterInterface $dataConverter,
        ?RPCConnectionInterface $rpc = null,
        ?ServiceCredentials $credentials = null,
        ?PluginRegistry $pluginRegistry = null,
        ?WorkflowClient $client = null,
        ?ActivityInvocationCacheInterface $activityCache = null,
        ?Connection $connection = null,
        string $namespace = 'default',
    ) {
        $this->activityCache = $activityCache ?? new FileActivityInvocationCache($dataConverter);

        parent::__construct(
            $dataConverter,
            $rpc,
            $credentials ?? ServiceCredentials::create(),
            $pluginRegistry,
            $client,
            $connection,
            $namespace,
        );
    }

    /**
     * @psalm-suppress UnsafeInstantiation
     */
    public static function create(
        ?DataConverterInterface $converter = null,
        ?RPCConnectionInterface $rpc = null,
        ?ServiceCredentials $credentials = null,
        ?PluginRegistry $pluginRegistry = null,
        ?WorkflowClient $client = null,
        ?Connection $connection = null,
        ?string $namespace = null,
        ?ActivityInvocationCacheInterface $activityCache = null,
    ): static {
        if ($rpc !== null && $connection !== null) {
            throw new \InvalidArgumentException(
                'Pass either a native Temporal connection or a custom worker RPC connection, not both.',
            );
        }

        $namespace ??= self::environmentValue('TEMPORAL_NAMESPACE') ?? 'default';
        if ($rpc === null) {
            $connection ??= new Connection(
                address: self::environmentValue('TEMPORAL_ADDRESS') ?? '127.0.0.1:7233',
                apiKey: ($credentials?->apiKey ?? '') !== '' ? $credentials->apiKey : null,
            );
        }

        return new static(
            $converter ?? DataConverter::createDefault(),
            $rpc,
            $credentials,
            $pluginRegistry,
            $client,
            $activityCache,
            $connection,
            $namespace,
        );
    }

    protected function decorateWorker(WorkerInterface $worker): WorkerInterface
    {
        \assert($worker instanceof \Temporal\Worker\DispatcherInterface);

        return new WorkerMock($worker, $this->activityCache);
    }

    private static function environmentValue(string $name): ?string
    {
        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
