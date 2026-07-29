<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal;

use Carbon\CarbonInterval;
use Doctrine\Common\Annotations\Reader;
use JetBrains\PhpStorm\Pure;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Spiral\Attributes\AnnotationReader;
use Spiral\Attributes\AttributeReader;
use Spiral\Attributes\Composite\SelectiveReader;
use Spiral\Attributes\ReaderInterface;
use Temporal\Client\WorkflowClient;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Internal\Events\EventEmitterTrait;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Marshaller\Mapper\AttributeMapperFactory;
use Temporal\Internal\Marshaller\Marshaller;
use Temporal\Internal\Marshaller\MarshallerInterface;
use Temporal\Internal\Queue\ArrayQueue;
use Temporal\Internal\Queue\QueueInterface;
use Temporal\Internal\Repository\ArrayRepository;
use Temporal\Internal\Repository\RepositoryInterface;
use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Transport\Client;
use Temporal\Internal\Transport\ClientInterface;
use Temporal\Internal\Transport\Router;
use Temporal\Internal\Transport\RouterInterface;
use Temporal\Internal\Transport\Server;
use Temporal\Internal\Transport\ServerInterface;
use Temporal\Internal\Workflow\Logger;
use Temporal\Plugin\CompositePipelineProvider;
use Temporal\Plugin\PluginInterface;
use Temporal\Plugin\PluginRegistry;
use Temporal\Plugin\WorkerFactoryPluginContext;
use Temporal\Plugin\WorkerPluginContext;
use Temporal\Plugin\WorkerPluginInterface;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\Environment\EnvironmentInterface;
use Temporal\Worker\Logger\StderrLogger;
use Temporal\Worker\LoopInterface;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\Command\Client\UpdateResponse;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\Transport\Command\Server\TickInfo;
use Temporal\Worker\Transport\Command\ServerRequestInterface;
use Temporal\Worker\Transport\Command\ServerResponseInterface;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\TrueAsync\CoreRpcConnection;
use Temporal\Worker\TrueAsync\CoresdkWorkflowCodec;
use Temporal\Worker\TrueAsync\NativeWorkerRuntime;
use Temporal\Worker\TrueAsync\NonDeterministicWorkflowException;
use Temporal\Worker\TrueAsync\NullRpcConnection;
use Temporal\Worker\TrueAsync\QueryServerRequest;
use Temporal\Worker\Worker;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\Worker\WorkflowPanicPolicy;
use TrueAsync\Temporal\Core\Connection as CoreConnection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * Native TrueAsync worker factory.
 *
 * The public registration API is unchanged: create a factory, register one or
 * more task-queue workers, then call {@see run()}. Each task queue is backed by
 * a Temporal Rust-core worker. Long polls and client RPCs park their current
 * coroutine, so workflows and activities share one PHP process without a host
 * process or a socket bridge.
 *
 * ```php
 * $factory = WorkerFactory::create(
 *     connection: new \TrueAsync\Temporal\Core\Connection('127.0.0.1:7233'),
 * );
 * $worker = $factory->newWorker('default');
 * $worker->registerWorkflowTypes(MyWorkflow::class);
 * $worker->registerActivityImplementations(new MyActivities());
 * $factory->run();
 * ```
 */
class WorkerFactory implements WorkerFactoryInterface, LoopInterface
{
    use EventEmitterTrait;

    private const ERROR_QUEUE_NOT_FOUND = 'Cannot find a worker for task queue "%s"';
    private const HEADER_TASK_QUEUE = 'taskQueue';
    private const HEADER_NAMESPACE = 'namespace';
    private const DEFAULT_ADDRESS = '127.0.0.1:7233';
    private const DEFAULT_NAMESPACE = 'default';
    private const DEFAULT_DETERMINISM_BUDGET_MS = 1000;

    protected DataConverterInterface $converter;
    protected ReaderInterface $reader;
    protected RouterInterface $router;

    /** @var RepositoryInterface<WorkerInterface> */
    protected RepositoryInterface $queues;

    protected ClientInterface $client;
    protected ServerInterface $server;
    protected QueueInterface $responses;

    /** @var MarshallerInterface<array> */
    protected MarshallerInterface $marshaller;

    protected EnvironmentInterface $env;
    protected PluginRegistry $pluginRegistry;
    protected RPCConnectionInterface $rpc;

    /** @var array<non-empty-string, NativeWorkerRuntime> */
    private array $nativeRuntimes = [];

    /** @var array<non-empty-string, WorkerOptions> */
    private array $workerOptions = [];

    private ?CoresdkWorkflowCodec $workflowCodec = null;
    private bool $captureWorkflowEvictions = false;

    /** @var list<array{runId: string, reason: int, reasonName: string, message: string}> */
    private array $workflowEvictions = [];

    private bool $running = false;

    public function __construct(
        DataConverterInterface $dataConverter,
        ?RPCConnectionInterface $rpc = null,
        ?ServiceCredentials $credentials = null,
        ?PluginRegistry $pluginRegistry = null,
        ?WorkflowClient $client = null,
        private readonly ?CoreConnection $connection = null,
        private readonly string $namespace = self::DEFAULT_NAMESPACE,
    ) {
        if ($namespace === '') {
            throw new \InvalidArgumentException('Temporal namespace cannot be empty.');
        }

        $this->rpc = $rpc ?? new NullRpcConnection();
        $this->pluginRegistry = new PluginRegistry();

        // Propagate worker plugins from the client first.
        if ($client !== null) {
            $this->pluginRegistry->merge($client->getWorkerPlugins());
        }

        // Add factory plugins after client plugins.
        if ($pluginRegistry !== null) {
            $this->pluginRegistry->merge($pluginRegistry->getPlugins(PluginInterface::class));
        }

        $factoryContext = new WorkerFactoryPluginContext(dataConverter: $dataConverter);
        $workerPlugins = $this->pluginRegistry->getPlugins(WorkerPluginInterface::class);
        /** @see WorkerPluginInterface::configureWorkerFactory() */
        Pipeline::prepare($workerPlugins)
            ->with(static fn() => null, 'configureWorkerFactory')($factoryContext);

        $this->converter = $factoryContext->getDataConverter() ?? $dataConverter;
        $this->boot($credentials ?? ServiceCredentials::create());
    }

    /**
     * Create a native worker factory.
     *
     * Passing an explicit legacy RPC connection creates an engine-only factory;
     * this is retained for the in-process testing helpers and the
     * {@see \Temporal\Worker\TrueAsync\TemporalWorker} compatibility facade.
     * Normal applications should omit `$rpc` and optionally pass a native core
     * connection.
     */
    public static function create(
        ?DataConverterInterface $converter = null,
        ?RPCConnectionInterface $rpc = null,
        ?ServiceCredentials $credentials = null,
        ?PluginRegistry $pluginRegistry = null,
        ?WorkflowClient $client = null,
        ?CoreConnection $connection = null,
        ?string $namespace = null,
    ): static {
        if ($rpc !== null && $connection !== null) {
            throw new \InvalidArgumentException(
                'Pass either a native Temporal connection or a custom worker RPC connection, not both.',
            );
        }

        $namespace ??= self::environmentValue('TEMPORAL_NAMESPACE') ?? self::DEFAULT_NAMESPACE;

        if ($rpc === null) {
            $connection ??= new CoreConnection(
                address: self::environmentValue('TEMPORAL_ADDRESS') ?? self::DEFAULT_ADDRESS,
                apiKey: ($credentials?->apiKey ?? '') !== '' ? $credentials->apiKey : null,
            );
        }

        return new static(
            $converter ?? DataConverter::createDefault(),
            $rpc,
            $credentials,
            $pluginRegistry,
            $client,
            $connection,
            $namespace,
        );
    }

    public function newWorker(
        string $taskQueue = self::DEFAULT_TASK_QUEUE,
        ?WorkerOptions $options = null,
        ?ExceptionInterceptorInterface $exceptionInterceptor = null,
        ?PipelineProvider $interceptorProvider = null,
        ?LoggerInterface $logger = null,
    ): WorkerInterface {
        if ($this->running) {
            throw new \LogicException('Workers cannot be registered after WorkerFactory::run() has started.');
        }

        $options ??= WorkerOptions::new();

        $workerContext = new WorkerPluginContext(
            taskQueue: $taskQueue,
            workerOptions: $options,
            exceptionInterceptor: $exceptionInterceptor,
        );
        $workerPlugins = $this->pluginRegistry->getPlugins(WorkerPluginInterface::class);
        /** @see WorkerPluginInterface::configureWorker() */
        Pipeline::prepare($workerPlugins)
            ->with(static fn() => null, 'configureWorker')($workerContext);

        $options = $workerContext->getWorkerOptions();
        $this->assertSupportedWorkerOptions($options);

        $provider = new CompositePipelineProvider(
            $workerContext->getInterceptors(),
            $interceptorProvider ?? new SimplePipelineProvider(),
        );

        $core = null;
        $workerRpc = $this->rpc;
        if ($this->connection !== null) {
            $core = new CoreWorker(
                $this->connection,
                $taskQueue,
                $this->namespace,
                self::positiveOr($options->maxConcurrentActivityExecutionSize, 100),
                $this->coreWorkerOptions($options),
            );
            $workerRpc = new CoreRpcConnection($core);
        }

        $worker = new Worker(
            $taskQueue,
            $options,
            ServiceContainer::fromWorkerFactory(
                $this,
                $workerContext->getExceptionInterceptor() ?? ExceptionInterceptor::createDefault(),
                $provider,
                new Logger(
                    $logger ?? new StderrLogger(),
                    $options->enableLoggingInReplay,
                    $taskQueue,
                ),
            ),
            $workerRpc,
        );
        $worker = $this->decorateWorker($worker);

        /** @see WorkerPluginInterface::initializeWorker() */
        Pipeline::prepare($workerPlugins)
            ->with(static fn() => null, 'initializeWorker')($worker);

        $this->queues->add($worker);
        $this->workerOptions[$taskQueue] = $options;

        if ($core !== null) {
            \assert($workerRpc instanceof CoreRpcConnection);
            $this->nativeRuntimes[$taskQueue] = new NativeWorkerRuntime(
                core: $core,
                factory: $this,
                worker: $worker,
                dataConverter: $this->converter,
                taskQueue: $taskQueue,
                rpc: $workerRpc,
                pollWorkflows: !$options->disableWorkflowWorker,
                pollActivities: true,
            );
        }

        return $worker;
    }

    public function getPluginRegistry(): PluginRegistry
    {
        return $this->pluginRegistry;
    }

    public function getReader(): ReaderInterface
    {
        return $this->reader;
    }

    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    public function getQueue(): QueueInterface
    {
        return $this->responses;
    }

    public function getDataConverter(): DataConverterInterface
    {
        return $this->converter;
    }

    /**
     * @return MarshallerInterface<array>
     */
    public function getMarshaller(): MarshallerInterface
    {
        return $this->marshaller;
    }

    public function getEnvironment(): EnvironmentInterface
    {
        return $this->env;
    }

    /**
     * Run all registered native task-queue workers until shutdown.
     *
     * A failure in any poll loop initiates shutdown for every task queue, waits
     * for all sibling loops to settle, finalizes every Rust-core worker, and
     * then rethrows the original failure.
     */
    public function run(): int
    {
        if ($this->running) {
            throw new \LogicException('WorkerFactory::run() is already active.');
        }

        if ($this->nativeRuntimes === [] && \count($this->queues) > 0) {
            throw new \LogicException(
                'This WorkerFactory has no native Temporal connection and cannot poll. '
                . 'Use WorkerFactory::create(connection: ...) for an application worker.',
            );
        }

        $this->running = true;
        $plugins = $this->pluginRegistry->getPlugins(WorkerPluginInterface::class);
        $pipeline = Pipeline::prepare($plugins);

        try {
            /** @see WorkerPluginInterface::run() */
            return $pipeline->with(fn(): int => $this->runNativeRuntimes(), 'run')($this);
        } finally {
            $this->running = false;
        }
    }

    /**
     * Request graceful shutdown for every registered task queue.
     */
    public function shutdown(): void
    {
        foreach ($this->nativeRuntimes as $runtime) {
            $runtime->shutdown();
        }
    }

    /**
     * Retain core workflow-eviction metadata for an offline replay run.
     *
     * Live workers deliberately ignore this metadata: eviction, including
     * nondeterminism eviction, is run-scoped and must not terminate the worker.
     *
     * @internal
     */
    public function captureWorkflowEvictions(): void
    {
        $this->captureWorkflowEvictions = true;
        $this->workflowEvictions = [];
    }

    /**
     * @return list<array{runId: string, reason: int, reasonName: string, message: string}>
     *
     * @internal
     */
    public function drainWorkflowEvictions(): array
    {
        $evictions = $this->workflowEvictions;
        $this->workflowEvictions = [];
        $this->captureWorkflowEvictions = false;

        return $evictions;
    }

    /**
     * @internal
     */
    public function hasCapturedWorkflowEvictionReason(int $reason): bool
    {
        if (!$this->captureWorkflowEvictions) {
            return false;
        }

        foreach ($this->workflowEvictions as $eviction) {
            if ($eviction['reason'] === $reason) {
                return true;
            }
        }

        return false;
    }

    public function tick(): void
    {
        $this->emit(LoopInterface::ON_SIGNAL);
        $this->emit(LoopInterface::ON_CALLBACK);
        $this->emit(LoopInterface::ON_QUERY);
        $this->emit(LoopInterface::ON_TICK);
        $this->emit(LoopInterface::ON_FINALLY);
    }

    /**
     * Drive one encoded engine batch without a native poller.
     *
     * This is a narrow test seam for the SDK's historical deterministic-engine
     * fixtures. Application workers use {@see processActivation()}.
     *
     * @internal
     *
     * @param array<string, mixed> $headers
     */
    public function processEngineBatch(
        CodecInterface $codec,
        string $messages,
        array $headers,
    ): string {
        foreach ($codec->decode($messages, $headers) as $command) {
            $this->env->update($command->getTickInfo());

            if ($command instanceof ServerResponseInterface) {
                $this->client->dispatch($command);
                continue;
            }

            $this->server->dispatch($command, $headers);
        }

        $this->tick();

        return $codec->encode($this->responses);
    }

    /**
     * Apply one coresdk workflow activation and return its serialized
     * completion.
     *
     * @internal Called by the native workflow poll loop.
     */
    public function processActivation(string $activation, string $taskQueue): string
    {
        $codec = $this->workflowCodec ??= new CoresdkWorkflowCodec(
            $this->converter,
            $this->workflowVersioningBehavior(...),
        );
        $budgetMs = $this->determinismBudgetMs($taskQueue);

        $work = \Async\spawn(fn(): string => $this->applyActivation($codec, $activation, $taskQueue));
        $deadline = \Async\spawn(static fn() => \Async\delay($budgetMs));

        try {
            $completion = \Async\await($work, $deadline);
            $deadline->cancel();

            return $completion;
        } catch (\Throwable) {
            $work->cancel();

            return $codec->encodeFailure(new NonDeterministicWorkflowException(\sprintf(
                'Workflow task exceeded the determinism budget (%d ms): workflow code '
                . 'blocked the worker coroutine on the real reactor. Use Workflow::timer(), '
                . 'Workflow::executeActivity(), Workflow::await*() and other deterministic '
                . 'Workflow primitives instead of Async APIs or blocking I/O.',
                $budgetMs,
            )));
        }
    }

    protected function createReader(): ReaderInterface
    {
        if (\interface_exists(Reader::class)) {
            return new SelectiveReader([new AnnotationReader(), new AttributeReader()]);
        }

        return new AttributeReader();
    }

    /**
     * @return RepositoryInterface<WorkerInterface>
     */
    protected function createTaskQueue(): RepositoryInterface
    {
        return new ArrayRepository();
    }

    protected function createRouter(ServiceCredentials $credentials): RouterInterface
    {
        $router = new Router();
        $router->add(new Router\GetWorkerInfo(
            $this->queues,
            $this->marshaller,
            $credentials,
            $this->pluginRegistry,
        ));

        return $router;
    }

    protected function createQueue(): QueueInterface
    {
        return new ArrayQueue();
    }

    #[Pure]
    protected function createClient(): ClientInterface
    {
        return new Client($this->responses);
    }

    protected function createServer(): ServerInterface
    {
        return new Server($this->responses, $this->onRequest(...), $this->pluginRegistry);
    }

    /**
     * Testing factories may decorate the SDK worker while preserving the native
     * runtime and registration lifecycle.
     */
    protected function decorateWorker(WorkerInterface $worker): WorkerInterface
    {
        return $worker;
    }

    /**
     * @return MarshallerInterface<array>
     */
    protected function createMarshaller(ReaderInterface $reader): MarshallerInterface
    {
        return new Marshaller(new AttributeMapperFactory($reader));
    }

    private static function positiveOr(int $value, int $default): int
    {
        return $value > 0 ? $value : $default;
    }

    private static function intervalMilliseconds(\DateInterval $interval): int
    {
        return (int) \ceil(CarbonInterval::instance($interval)->totalMilliseconds);
    }

    private static function environmentValue(string $name): ?string
    {
        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function boot(ServiceCredentials $credentials): void
    {
        $this->reader = $this->createReader();
        $this->marshaller = $this->createMarshaller($this->reader);
        $this->queues = $this->createTaskQueue();
        $this->router = $this->createRouter($credentials);
        $this->responses = $this->createQueue();
        $this->client = $this->createClient();
        $this->server = $this->createServer();
        $this->env = new Environment();
    }

    private function runNativeRuntimes(): int
    {
        if ($this->nativeRuntimes === []) {
            return 0;
        }

        $failure = null;
        $tasks = [];

        foreach ($this->nativeRuntimes as $runtime) {
            $tasks[] = \Async\spawn(function () use ($runtime, &$failure): void {
                try {
                    $runtime->run();
                } catch (\Throwable $error) {
                    $failure ??= $error;
                    $this->shutdown();
                }
            });
        }

        try {
            // The guards above turn every task into a successful settlement
            // after recording its error.
            \Async\await_all_or_fail($tasks);
        } finally {
            // Cancellation of the factory coroutine must not orphan native
            // pollers. Stop every core and settle the whole child scope before
            // returning control to the caller.
            $this->shutdown();
            \Async\protect(static fn() => \Async\await_all($tasks));
        }

        if ($failure !== null) {
            throw $failure;
        }

        return 0;
    }

    private function applyActivation(CoresdkWorkflowCodec $codec, string $activation, string $taskQueue): string
    {
        $headers = [
            self::HEADER_TASK_QUEUE => $taskQueue,
            self::HEADER_NAMESPACE => $this->namespace,
        ];
        $tick = null;
        $completion = '';

        try {
            foreach ($codec->decode($activation, $headers) as $command) {
                $tick = $command->getTickInfo();
                $this->env->update($tick);

                if ($command instanceof ServerResponseInterface) {
                    $this->client->dispatch($command);
                    continue;
                }

                if ($command instanceof QueryServerRequest) {
                    $queryId = $command->queryId;
                    $worker = $this->queues->find($taskQueue) ?? throw new \LogicException(
                        "No worker registered for task queue {$taskQueue}.",
                    );
                    $worker->dispatch($command, $headers)->then(
                        static fn(?ValuesInterface $values) => $codec->recordQuerySuccess($queryId, $values),
                        static fn(\Throwable $error) => $codec->recordQueryFailure($queryId, $error),
                    );
                    continue;
                }

                $this->server->dispatch($command, $headers);
            }

            $this->tick();
            $this->drainIntoCodec($codec, $tick);
            $completion = $codec->encodeStaged();
        } catch (\Throwable $error) {
            foreach ($this->responses as $ignored) {
                // Discard commands queued before this failed activation.
            }

            $completion = $codec->encodeFailure($error);
        } finally {
            $evictions = $codec->drainEvictions();
            if ($this->captureWorkflowEvictions && $evictions !== []) {
                \array_push($this->workflowEvictions, ...$evictions);
            }
        }

        return $completion;
    }

    private function drainIntoCodec(CoresdkWorkflowCodec $codec, ?TickInfo $tick): void
    {
        do {
            $synthesize = [];
            foreach ($this->responses as $response) {
                if ($response instanceof RequestInterface) {
                    foreach ($codec->stage($response) as $commandId) {
                        $synthesize[] = $commandId;
                    }
                } elseif ($response instanceof UpdateResponse) {
                    $codec->stageUpdateResponse($response);
                }
            }

            $versions = $codec->drainVersionResolutions();

            if (($synthesize === [] && $versions === []) || $tick === null) {
                return;
            }

            foreach ($synthesize as $commandId) {
                $this->client->dispatch(new FailureResponse(
                    failure: new CanceledFailure('canceled'),
                    id: $commandId,
                    info: $tick,
                ));
            }

            foreach ($versions as $version) {
                $this->client->dispatch(new SuccessResponse(
                    values: EncodedValues::fromValues([$version['version']], $this->converter),
                    id: $version['id'],
                    info: $tick,
                ));
            }

            $this->tick();
        } while (true);
    }

    private function onRequest(ServerRequestInterface $request, array $headers): PromiseInterface
    {
        if (!isset($headers[self::HEADER_TASK_QUEUE])) {
            return $this->router->dispatch($request, $headers);
        }

        return $this->findWorkerByTaskQueue(
            $this->findTaskQueueNameOrFail($headers),
        )->dispatch($request, $headers);
    }

    private function findWorkerByTaskQueue(string $taskQueue): WorkerInterface
    {
        $worker = $this->queues->find($taskQueue);

        if ($worker === null) {
            throw new \OutOfRangeException(\sprintf(self::ERROR_QUEUE_NOT_FOUND, $taskQueue));
        }

        return $worker;
    }

    private function findTaskQueueNameOrFail(array $headers): string
    {
        $taskQueue = $headers[self::HEADER_TASK_QUEUE];

        if (!\is_string($taskQueue)) {
            throw new \InvalidArgumentException(\sprintf(
                'Header "%s" must be a string, %s given.',
                self::HEADER_TASK_QUEUE,
                \get_debug_type($taskQueue),
            ));
        }

        return $taskQueue;
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function coreWorkerOptions(WorkerOptions $options): array
    {
        $activitySlots = self::positiveOr($options->maxConcurrentActivityExecutionSize, 100);
        $result = [
            'workflowSlots' => self::positiveOr($options->maxConcurrentWorkflowTaskExecutionSize, 100),
            'localActivitySlots' => self::positiveOr($options->maxConcurrentLocalActivityExecutionSize, 100),
            'activityPollers' => self::positiveOr($options->maxConcurrentActivityTaskPollers, 5),
            'workflowPollers' => self::positiveOr($options->maxConcurrentWorkflowTaskPollers, 2),
            'identity' => $options->identity,
            'maxActivitiesPerSecond' => $options->workerActivitiesPerSecond,
            'maxTaskQueueActivitiesPerSecond' => $options->taskQueueActivitiesPerSecond,
            'disableWorkflows' => $options->disableWorkflowWorker,
            'maxEagerActivityReservationsPerWorkflowTask' => $options->disableEagerActivities
                || $options->taskQueueActivitiesPerSecond > 0.0
                ? 0
                : self::positiveOr($options->maxConcurrentEagerActivityExecutionSize, $activitySlots),
        ];

        if ($options->stickyScheduleToStartTimeout !== null) {
            $result['stickyScheduleToStartTimeoutMs'] = self::intervalMilliseconds(
                $options->stickyScheduleToStartTimeout,
            );
        }
        if ($options->workerStopTimeout !== null) {
            $result['gracefulShutdownMs'] = self::intervalMilliseconds($options->workerStopTimeout);
        }
        if ($options->maxHeartbeatThrottleInterval !== null) {
            $result['maxHeartbeatThrottleMs'] = self::intervalMilliseconds(
                $options->maxHeartbeatThrottleInterval,
            );
        }

        $result['buildId'] = $options->buildID;
        if (isset($options->deploymentOptions)) {
            /** @var array{
             *     UseVersioning: bool,
             *     Version: null|array{DeploymentName: string, BuildId: string},
             *     DefaultVersioningBehavior: int
             * } $deployment
             */
            $deployment = $this->marshaller->marshal($options->deploymentOptions);
            $version = $deployment['Version'];
            if ($version === null) {
                if ($deployment['UseVersioning']) {
                    throw new \InvalidArgumentException(
                        'Worker deployment versioning requires a deployment version.',
                    );
                }

                return $result;
            }

            $result['versioningStrategy'] = 1;
            $result['deploymentName'] = $version['DeploymentName'];
            $result['buildId'] = $version['BuildId'];
            $result['deploymentUseVersioning'] = $deployment['UseVersioning'];
            $result['versioningBehavior'] = $deployment['DefaultVersioningBehavior'];
        } elseif ($options->useBuildIDForVersioning) {
            if ($options->buildID === '') {
                throw new \InvalidArgumentException(
                    'Legacy build ID versioning requires a non-empty build ID.',
                );
            }
            $result['versioningStrategy'] = 2;
        }

        return $result;
    }

    private function assertSupportedWorkerOptions(WorkerOptions $options): void
    {
        $unsupported = [];
        $options->workflowPanicPolicy === WorkflowPanicPolicy::BlockWorkflow
            or $unsupported[] = 'workflowPanicPolicy';
        $options->workerLocalActivitiesPerSecond === 0.0
            or $unsupported[] = 'workerLocalActivitiesPerSecond';
        !$options->enableSessionWorker or $unsupported[] = 'enableSessionWorker';
        $options->sessionResourceId === null or $unsupported[] = 'sessionResourceId';
        $options->maxConcurrentSessionExecutionSize === 1000
            or $unsupported[] = 'maxConcurrentSessionExecutionSize';
        !$options->localActivityWorkerOnly or $unsupported[] = 'localActivityWorkerOnly';
        !$options->disableRegistrationAliasing or $unsupported[] = 'disableRegistrationAliasing';
        $options->maxConcurrentNexusTaskExecutionSize === 0
            or $unsupported[] = 'maxConcurrentNexusTaskExecutionSize';
        $options->maxConcurrentNexusTaskPollers === 0
            or $unsupported[] = 'maxConcurrentNexusTaskPollers';

        if ($unsupported !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'The native Temporal core bridge does not yet support these WorkerOptions: %s.',
                \implode(', ', $unsupported),
            ));
        }
    }

    private function determinismBudgetMs(string $taskQueue): int
    {
        $timeout = $this->workerOptions[$taskQueue]->deadlockDetectionTimeout ?? null;

        return $timeout === null
            ? self::DEFAULT_DETERMINISM_BUDGET_MS
            : \max(1, self::intervalMilliseconds($timeout));
    }

    private function workflowVersioningBehavior(string $taskQueue, string $workflowType): int
    {
        $worker = $this->queues->find($taskQueue);
        if (!$worker instanceof WorkerInterface) {
            return 0;
        }

        foreach ($worker->getWorkflows() as $workflow) {
            if ($workflow->getID() === $workflowType) {
                return $workflow->getVersioningBehavior()->value;
            }
        }

        return 0;
    }
}
