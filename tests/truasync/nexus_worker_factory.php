<?php

declare(strict_types=1);

/**
 * Native Nexus end-to-end check over WorkerFactory + TrueAsync Temporal Core.
 *
 * Requires the `temporal` extension, a Temporal Server >= 1.31, and a Temporal
 * CLI. The endpoint is created and deleted by this process.
 *
 *   TEMPORAL_CLI_BINARY=./temporal php tests/truasync/nexus_worker_factory.php [address]
 *
 * Exits 0 on pass, 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Carbon\CarbonInterval;
use Symfony\Component\Process\Process;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\NexusHandlerFailure;
use Temporal\Exception\Failure\NexusOperationFailure;
use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Nexus\Attribute\AsyncOperation;
use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\OperationException;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\WorkflowHandle;
use Temporal\WorkerFactory;
use Temporal\Workflow;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\NexusOperationOptions;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use TrueAsync\Temporal\Core\Connection;

use function Async\await;
use function Async\spawn;

#[Service(name: 'TrueAsyncNexusE2EService')]
final class TrueAsyncNexusE2EService
{
    #[Operation]
    public function sync(string $input): string
    {
        return "sync:{$input}";
    }

    #[AsyncOperation(output: 'string')]
    public function async(string $input): WorkflowHandle
    {
        return WorkflowHandle::fromWorkflowMethod(
            TrueAsyncNexusE2EAsyncWorkflow::class,
            WorkflowOptions::new()->withWorkflowId(Nexus::getStartDetails()->requestId),
            $input,
        );
    }

    #[Operation]
    public function handlerError(string $input): string
    {
        throw HandlerException::create(ErrorType::BadRequest, "bad-request:{$input}");
    }

    #[Operation]
    public function terminalFailed(string $input): string
    {
        throw OperationException::failed("terminal-failed:{$input}");
    }

    #[Operation]
    public function terminalCanceled(string $input): string
    {
        throw OperationException::canceled("terminal-canceled:{$input}");
    }

    #[AsyncOperation(output: 'string')]
    public function longRunning(string $input): WorkflowHandle
    {
        return WorkflowHandle::fromWorkflowMethod(
            TrueAsyncNexusE2ELongWorkflow::class,
            WorkflowOptions::new()->withWorkflowId(Nexus::getStartDetails()->requestId),
            $input,
        );
    }
}

#[WorkflowInterface]
final class TrueAsyncNexusE2EAsyncWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncNexusE2EAsyncWorkflow')]
    public function run(string $input): \Generator
    {
        yield Workflow::timer(CarbonInterval::milliseconds(100));

        return "async:{$input}";
    }
}

#[WorkflowInterface]
final class TrueAsyncNexusE2ELongWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncNexusE2ELongWorkflow')]
    public function run(string $input): \Generator
    {
        yield Workflow::timer(CarbonInterval::seconds(30));

        return "unexpected-long-completion:{$input}";
    }
}

#[WorkflowInterface]
final class TrueAsyncNexusE2ECallerWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncNexusE2ECallerWorkflow')]
    public function run(string $endpoint): \Generator
    {
        $stub = Workflow::newNexusServiceStub(
            TrueAsyncNexusE2EService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(20)),
        );

        $result = [
            'sync' => yield $stub->sync('payload'),
            'async' => yield $stub->async('payload'),
        ];

        try {
            yield $stub->handlerError('payload');
            $result['handlerError'] = 'unexpected-completion';
        } catch (NexusOperationFailure $failure) {
            $cause = $failure->getPrevious();
            $result['handlerError'] = $cause instanceof NexusHandlerFailure
                ? $cause->getType()
                : 'wrong-cause:' . \get_debug_type($cause);
        }

        try {
            yield $stub->terminalFailed('payload');
            $result['terminalFailed'] = 'unexpected-completion';
        } catch (NexusOperationFailure $failure) {
            $result['terminalFailed'] = self::failureChainContains($failure, 'terminal-failed')
                ? 'failed'
                : 'wrong-cause:' . \get_debug_type($failure->getPrevious());
        }

        try {
            yield $stub->terminalCanceled('payload');
            $result['terminalCanceled'] = 'unexpected-completion';
        } catch (NexusOperationFailure $failure) {
            $result['terminalCanceled'] = $failure->getPrevious() instanceof CanceledFailure
                ? 'canceled'
                : 'wrong-cause:' . \get_debug_type($failure->getPrevious());
        }

        $cancelStub = Workflow::newNexusServiceStub(
            TrueAsyncNexusE2EService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(20))
                ->withCancellationType(NexusOperationCancellationType::WaitCompleted),
        );
        $cancelPromise = null;
        $scope = Workflow::async(static function () use ($cancelStub, &$cancelPromise): void {
            $cancelPromise = $cancelStub->longRunning('cancel');
        });

        // Flush the schedule/start before requesting cancellation.
        yield Workflow::timer(CarbonInterval::milliseconds(500));
        $scope->cancel();

        try {
            yield $cancelPromise;
            $result['cancelRequest'] = 'unexpected-completion';
        } catch (NexusOperationFailure $failure) {
            $result['cancelRequest'] = $failure->getPrevious() instanceof CanceledFailure
                ? 'canceled'
                : 'wrong-cause:' . \get_debug_type($failure->getPrevious());
        }

        $timeoutStub = Workflow::newNexusServiceStub(
            TrueAsyncNexusE2EService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(10))
                ->withStartToCloseTimeout(CarbonInterval::seconds(1)),
        );

        try {
            yield $timeoutStub->longRunning('timeout');
            $result['timeout'] = -1;
        } catch (NexusOperationFailure $failure) {
            $cause = $failure->getPrevious();
            $result['timeout'] = $cause instanceof TimeoutFailure
                ? $cause->getTimeoutType()
                : -2;
        }

        return $result;
    }

    private static function failureChainContains(\Throwable $failure, string $needle): bool
    {
        do {
            if (\str_contains($failure->getMessage(), $needle)) {
                return true;
            }
            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return false;
    }
}

/**
 * @param list<string> $arguments
 */
function trueAsyncNexusCli(string $binary, array $arguments): string
{
    $process = new Process([$binary, ...$arguments]);
    $process->setTimeout(20);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new \RuntimeException(\sprintf(
            "Temporal CLI failed (%d): %s\n%s",
            $process->getExitCode(),
            $process->getCommandLine(),
            \trim($process->getErrorOutput() . "\n" . $process->getOutput()),
        ));
    }

    return \trim($process->getOutput());
}

function trueAsyncNexusCliBinary(): string
{
    $configured = \getenv('TEMPORAL_CLI_BINARY');
    if (\is_string($configured) && $configured !== '') {
        return $configured;
    }

    $repositoryBinary = __DIR__ . '/../../temporal';

    return \is_file($repositoryBinary) ? $repositoryBinary : 'temporal';
}

/**
 * @param array<int, int> $historyCounts
 */
function trueAsyncNexusHistoryCount(array $historyCounts, int $eventType): int
{
    return $historyCounts[$eventType] ?? 0;
}

$configuredAddress = \getenv('TEMPORAL_ADDRESS');
$address = $argv[1] ?? (\is_string($configuredAddress) && $configuredAddress !== ''
    ? $configuredAddress
    : '127.0.0.1:7233');
[$host, $port] = \explode(':', $address) + [1 => '7233'];
$probe = @\fsockopen($host, (int) $port, $errno, $errstr, 1.0);
if ($probe === false) {
    \fwrite(\STDERR, "FAIL: no Temporal server at {$address}\n");
    exit(1);
}
\fclose($probe);

foreach (['true_async', 'temporal'] as $extension) {
    if (!\extension_loaded($extension)) {
        \fwrite(\STDERR, "FAIL: {$extension} extension not loaded\n");
        exit(1);
    }
}

$namespace = 'default';
$suffix = \bin2hex(\random_bytes(5));
$taskQueue = "truasync-nexus-{$suffix}";
$workflowId = "truasync-nexus-caller-{$suffix}";
$endpoint = "truasync-nexus-{$suffix}";
$cli = trueAsyncNexusCliBinary();
$created = false;

try {
    trueAsyncNexusCli($cli, [
        'operator',
        'nexus',
        'endpoint',
        'create',
        '--disable-config-file',
        '--disable-config-env',
        '--address',
        $address,
        '--name',
        $endpoint,
        '--target-namespace',
        $namespace,
        '--target-task-queue',
        $taskQueue,
        '--output',
        'none',
    ]);
    $created = true;

    /**
     * @var array{
     *     serverVersion: string,
     *     result: array<string, mixed>,
     *     historyCounts: array<int, int>
     * } $runResult
     */
    $runResult = await(spawn(static function () use (
        $address,
        $namespace,
        $taskQueue,
        $workflowId,
        $endpoint,
    ): array {
        $clientConnection = new Connection($address);
        $serviceClient = TrueAsyncServiceClient::fromCore($clientConnection);
        $client = WorkflowClient::create($serviceClient);
        $factory = WorkerFactory::create(
            client: $client,
            connection: new Connection($address),
            namespace: $namespace,
        );
        $worker = $factory->newWorker($taskQueue);
        $worker->registerWorkflowTypes(
            TrueAsyncNexusE2ECallerWorkflow::class,
            TrueAsyncNexusE2EAsyncWorkflow::class,
            TrueAsyncNexusE2ELongWorkflow::class,
        );
        $worker->registerNexusServiceImplementation(new TrueAsyncNexusE2EService());

        $workerLoop = spawn(static fn(): int => $factory->run());

        try {
            $info = $serviceClient->GetSystemInfo(new \Temporal\Api\Workflowservice\V1\GetSystemInfoRequest());
            $caller = $client->newUntypedWorkflowStub(
                'TrueAsyncNexusE2ECallerWorkflow',
                WorkflowOptions::new()
                    ->withTaskQueue($taskQueue)
                    ->withWorkflowId($workflowId)
                    ->withWorkflowExecutionTimeout(CarbonInterval::seconds(45)),
            );
            $run = $client->start($caller, $endpoint);
            $result = $run->getResult('array', 40);

            $historyCounts = [];
            foreach ($client->getWorkflowHistory($run->getExecution()) as $event) {
                $eventType = $event->getEventType();
                $historyCounts[$eventType] = ($historyCounts[$eventType] ?? 0) + 1;
            }

            return [
                'serverVersion' => $info->getServerVersion(),
                'result' => $result,
                'historyCounts' => $historyCounts,
            ];
        } finally {
            $factory->shutdown();
            await($workerLoop);
        }
    }));

    $normalizedVersion = \preg_replace('/[^0-9.].*$/', '', $runResult['serverVersion']);
    if (!\is_string($normalizedVersion) || \version_compare($normalizedVersion, '1.31.0', '<')) {
        throw new \RuntimeException(
            "Temporal Server >= 1.31.0 required, got {$runResult['serverVersion']}.",
        );
    }

    $expected = [
        'sync' => 'sync:payload',
        'async' => 'async:payload',
        'handlerError' => 'BAD_REQUEST',
        'terminalFailed' => 'failed',
        'terminalCanceled' => 'canceled',
        'cancelRequest' => 'canceled',
        'timeout' => TimeoutType::TIMEOUT_TYPE_START_TO_CLOSE,
    ];
    if ($runResult['result'] !== $expected) {
        throw new \RuntimeException(\sprintf(
            "Unexpected caller result.\nExpected: %s\nActual:   %s",
            \json_encode($expected, \JSON_THROW_ON_ERROR),
            \json_encode($runResult['result'], \JSON_THROW_ON_ERROR),
        ));
    }

    $historyCounts = $runResult['historyCounts'];
    $requiredHistory = [
        EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED => 7,
        EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED => 2,
        EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED => 2,
        EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED => 2,
        EventType::EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED => 1,
        EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT => 1,
    ];
    foreach ($requiredHistory as $eventType => $minimum) {
        $actual = trueAsyncNexusHistoryCount($historyCounts, $eventType);
        if ($actual < $minimum) {
            throw new \RuntimeException(
                "Expected at least {$minimum} history events of type {$eventType}, got {$actual}.",
            );
        }
    }

    \fwrite(
        \STDOUT,
        \sprintf(
            "PASS: server=%s workflow=%s sync=ok async=ok handler-error=ok "
            . "failed=ok canceled=ok cancel-request=ok timeout=ok\n",
            $runResult['serverVersion'],
            $workflowId,
        ),
    );
} catch (\Throwable $error) {
    \fwrite(\STDERR, 'FAIL: ' . $error::class . ": {$error->getMessage()}\n");
    $exitCode = 1;
} finally {
    if ($created) {
        try {
            trueAsyncNexusCli($cli, [
                'operator',
                'nexus',
                'endpoint',
                'delete',
                '--disable-config-file',
                '--disable-config-env',
                '--address',
                $address,
                '--name',
                $endpoint,
                '--output',
                'none',
            ]);
        } catch (\Throwable $cleanupError) {
            \fwrite(\STDERR, "FAIL: endpoint cleanup: {$cleanupError->getMessage()}\n");
            $exitCode = 1;
        }
    }
}

exit($exitCode ?? 0);
