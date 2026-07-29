<?php

declare(strict_types=1);

namespace Temporal\Testing\Replay;

use Coresdk\WorkflowActivation\RemoveFromCache\EvictionReason;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Internal\Declaration\Reader\WorkflowReader;
use Temporal\Testing\Replay\Exception\InternalServerException;
use Temporal\Testing\Replay\Exception\InvalidArgumentException;
use Temporal\Testing\Replay\Exception\NonDeterministicWorkflowException;
use Temporal\Testing\Replay\Exception\RPCException;
use Temporal\Testing\Replay\Exception\ReplayerException;
use Temporal\Worker\DispatcherInterface;
use Temporal\Worker\TrueAsync\NativeWorkerRuntime;
use Temporal\Worker\TrueAsync\NullRpcConnection;
use Temporal\Worker\TrueAsync\WorkflowWorkerFactory;
use TrueAsync\Temporal\ConnectionException;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * Replays workflow history through the same Rust core and deterministic PHP
 * engine used by live native workers.
 */
final class WorkflowReplayer
{
    /** @var list<class-string> */
    private array $workflowTypes;

    private ?WorkflowClientInterface $workflowClient;
    private DataConverterInterface $dataConverter;

    /**
     * @param list<class-string> $workflowTypes Explicit workflow classes to
     *        consider. When omitted, declared workflow classes are discovered.
     */
    public function __construct(
        ?WorkflowClientInterface $workflowClient = null,
        ?DataConverterInterface $dataConverter = null,
        array $workflowTypes = [],
    ) {
        $this->workflowClient = $workflowClient;
        $this->dataConverter = $dataConverter ?? DataConverter::createDefault();
        $this->workflowTypes = $workflowTypes;
    }

    /**
     * Replays a workflow from an in-memory history.
     *
     * @throws ReplayerException
     */
    public function replayHistory(History $history): void
    {
        $this->replay($history, self::workflowTypeFromHistory($history));
    }

    /**
     * Fetch a workflow history from Temporal and replay it locally.
     *
     * @throws ReplayerException
     */
    public function replayFromServer(
        string $workflowType,
        \Temporal\Workflow\WorkflowExecution $execution,
    ): void {
        try {
            $history = $this->client()
                ->getWorkflowHistory($execution, skipArchival: true)
                ->getHistory();
        } catch (\Throwable $error) {
            throw new RPCException(
                $workflowType,
                $error->getMessage(),
                (int) $error->getCode(),
                $error,
            );
        }

        $this->replay($history, $workflowType);
    }

    /**
     * Download a workflow history as protobuf JSON.
     *
     * @param non-empty-string $workflowType
     * @param non-empty-string $savePath
     *
     * @throws ReplayerException
     */
    public function downloadHistory(
        string $workflowType,
        \Temporal\Workflow\WorkflowExecution $execution,
        string $savePath,
    ): void {
        try {
            $history = $this->client()
                ->getWorkflowHistory($execution, skipArchival: true)
                ->getHistory();
            $directory = \dirname($savePath);
            if (!\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
                throw new \RuntimeException("Cannot create history directory {$directory}.");
            }
            if (\file_put_contents($savePath, $history->serializeToJsonString()) === false) {
                throw new \RuntimeException("Cannot write workflow history to {$savePath}.");
            }
        } catch (\Throwable $error) {
            throw new ReplayerException(
                $workflowType,
                $error->getMessage(),
                (int) $error->getCode(),
                $error,
            );
        }
    }

    /**
     * Replay protobuf JSON exported by {@see downloadHistory()} or Temporal UI.
     *
     * @param non-empty-string $workflowType
     * @param non-empty-string|\SplFileInfo $path
     * @param int<0, max> $lastEventId
     *
     * @throws ReplayerException
     */
    public function replayFromJSON(
        string $workflowType,
        string|\SplFileInfo $path,
        int $lastEventId = 0,
    ): void {
        $path = $path instanceof \SplFileInfo ? $path->getPathname() : $path;

        try {
            if (!\is_file($path) || !\is_readable($path)) {
                throw new \RuntimeException("Cannot read workflow history from {$path}.");
            }

            $json = \file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException("Cannot read workflow history from {$path}.");
            }

            $history = new History();
            $history->mergeFromJsonString(self::normalizeHistoryJson($json), true);

            if ($lastEventId > 0) {
                $events = [];
                foreach ($history->getEvents() as $event) {
                    if ($event->getEventId() > $lastEventId) {
                        break;
                    }
                    $events[] = $event;
                }
                $history->setEvents($events);
            }
        } catch (\Throwable $error) {
            throw new InvalidArgumentException(
                $workflowType,
                $error->getMessage(),
                StatusCode::INVALID_ARGUMENT,
                $error,
            );
        }

        $this->replay($history, $workflowType);
    }

    private static function workflowTypeFromHistory(History $history): string
    {
        $firstEvent = $history->getEvents()[0] ?? null;

        return $firstEvent?->getWorkflowExecutionStartedEventAttributes()?->getWorkflowType()?->getName()
            ?: throw new \LogicException('History is empty or has no WorkflowExecutionStarted event.');
    }

    /**
     * Temporal UI and older CLI exports use short protobuf enum names such as
     * "WorkflowExecutionStarted", while current protobuf JSON expects
     * "EVENT_TYPE_WORKFLOW_EXECUTION_STARTED". Silently accepting the former
     * with mergeFromJsonString(..., true) turns every event into UNSPECIFIED and
     * makes Core reject the history before it can perform determinism checks.
     */
    private static function normalizeHistoryJson(string $json): string
    {
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Workflow history JSON must decode to an object.');
        }

        if (!isset($decoded['events']) || !\is_array($decoded['events'])) {
            throw new \InvalidArgumentException('Workflow history JSON must contain an events array.');
        }

        foreach ($decoded['events'] as &$event) {
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['eventType'] ?? null;
            if (!\is_string($type) || $type === '' || \str_starts_with($type, 'EVENT_TYPE_')) {
                continue;
            }

            $suffix = \preg_replace('/(?<!^)(?=[A-Z])/', '_', $type);
            if (!\is_string($suffix)) {
                throw new \InvalidArgumentException("Invalid history event type {$type}.");
            }

            $normalized = 'EVENT_TYPE_' . \strtoupper($suffix);
            EventType::value($normalized);
            $event['eventType'] = $normalized;
        }
        unset($event);

        return \json_encode($decoded, \JSON_THROW_ON_ERROR);
    }

    private static function mapReplayError(string $workflowType, \Throwable $error): ReplayerException
    {
        if ($error instanceof ReplayerException) {
            return $error;
        }

        $code = (int) $error->getCode();
        $message = $error->getMessage();

        return match (true) {
            $code === StatusCode::INVALID_ARGUMENT => new InvalidArgumentException(
                $workflowType,
                $message,
                $code,
                $error,
            ),
            $code === StatusCode::FAILED_PRECONDITION,
            \str_contains(\strtolower($message), 'non-determin') => new NonDeterministicWorkflowException(
                $workflowType,
                $message,
                $code,
                $error,
            ),
            $code === StatusCode::INTERNAL => new InternalServerException(
                $workflowType,
                $message,
                $code,
                $error,
            ),
            default => new ReplayerException($workflowType, $message, $code, $error),
        };
    }

    private function replay(History $history, string $workflowType): void
    {
        if (\count($history->getEvents()) === 0) {
            throw new \LogicException('History is empty or broken.');
        }

        $historyType = self::workflowTypeFromHistory($history);
        if ($historyType !== $workflowType) {
            throw new InvalidArgumentException(
                $workflowType,
                \sprintf(
                    'History workflow type is "%s", expected "%s".',
                    $historyType,
                    $workflowType,
                ),
                StatusCode::INVALID_ARGUMENT,
            );
        }

        $workflowClass = $this->resolveWorkflowClass($workflowType);
        if ($workflowClass === null) {
            throw new ReplayerException(
                $workflowType,
                "No declared workflow class is registered for type {$workflowType}. "
                . 'Pass it to WorkflowReplayer::$workflowTypes or load the class before replay.',
                StatusCode::NOT_FOUND,
            );
        }

        $taskQueue = 'replay-' . \substr(\hash('sha256', $workflowType), 0, 12);
        $core = CoreWorker::createReplay($taskQueue);
        $factory = WorkflowWorkerFactory::create(
            converter: $this->dataConverter,
            rpc: new NullRpcConnection(),
        );
        $factory->captureWorkflowEvictions();
        $worker = $factory->newWorker($taskQueue);
        $worker->registerWorkflowTypes($workflowClass);
        if (!$worker instanceof DispatcherInterface) {
            throw new \LogicException('Replay worker does not implement the SDK dispatcher.');
        }

        $runtime = new NativeWorkerRuntime(
            core: $core,
            factory: $factory,
            worker: $worker,
            dataConverter: $this->dataConverter,
            taskQueue: $taskQueue,
            rpc: null,
            pollWorkflows: true,
            pollActivities: false,
        );

        try {
            $workflowId = 'replay-' . \substr(\hash('sha256', $history->serializeToString()), 0, 24);
            $core->pushReplayHistory($workflowId, $history->serializeToString());
            $core->closeReplayHistory();
            $runtime->run();

            foreach ($factory->drainWorkflowEvictions() as $eviction) {
                if ($eviction['reason'] !== EvictionReason::NONDETERMINISM) {
                    continue;
                }

                $message = $eviction['message'] !== ''
                    ? $eviction['message']
                    : 'Workflow replay was non-deterministic.';
                throw new NonDeterministicWorkflowException(
                    $workflowType,
                    $message,
                    StatusCode::FAILED_PRECONDITION,
                );
            }
        } catch (ConnectionException $error) {
            throw new RPCException(
                $workflowType,
                $error->getMessage(),
                (int) $error->getCode(),
                $error,
            );
        } catch (\Throwable $error) {
            throw self::mapReplayError($workflowType, $error);
        }
    }

    /**
     * @return class-string|null
     */
    private function resolveWorkflowClass(string $workflowType): ?string
    {
        $factory = WorkflowWorkerFactory::create(
            converter: $this->dataConverter,
            rpc: new NullRpcConnection(),
        );
        $reader = new WorkflowReader($factory->getReader());
        $classes = $this->workflowTypes === [] ? \get_declared_classes() : $this->workflowTypes;

        foreach ($classes as $class) {
            try {
                $prototype = $reader->fromClass($class);
            } catch (\Throwable) {
                continue;
            }

            if ($prototype->getID() === $workflowType) {
                return $class;
            }
        }

        return null;
    }

    private function client(): WorkflowClientInterface
    {
        if ($this->workflowClient !== null) {
            return $this->workflowClient;
        }

        $address = \getenv('TEMPORAL_ADDRESS');
        $namespace = \getenv('TEMPORAL_NAMESPACE');

        $options = (new ClientOptions())->withNamespace(
            \is_string($namespace) && $namespace !== '' ? $namespace : 'default',
        );

        return $this->workflowClient = WorkflowClient::create(
            serviceClient: ServiceClient::create(
                \is_string($address) && $address !== '' ? $address : '127.0.0.1:7233',
            ),
            options: $options,
            converter: $this->dataConverter,
        );
    }
}
