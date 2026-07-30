<?php

declare(strict_types=1);

/**
 * Standalone Activity end-to-end check over WorkerFactory + TrueAsync Core.
 *
 * Requires the `temporal` extension and Temporal Server 1.31+:
 *
 *   php tests/truasync/standalone_activity.php [address]
 *
 * Covers start/describe/result, durable handle reattachment, failure mapping,
 * Visibility list/count, cooperative cancellation, and asynchronous completion.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Activity;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Client\Activity\ActivityOptions as StandaloneActivityOptions;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Common\RetryOptions;
use Temporal\DataConverter\ActivitySerializationContext;
use Temporal\Exception\Client\ActivityCanceledException;
use Temporal\Exception\Client\ActivityExecutionFailedException;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\WorkerFactory;
use TrueAsync\Temporal\Core\Connection;

use function Async\await;
use function Async\delay;
use function Async\spawn;

#[ActivityInterface(prefix: 'StandaloneActivityE2E.')]
final class StandaloneActivityE2E
{
    public function __construct(private readonly \stdClass $state) {}

    #[ActivityMethod]
    public function inspect(string $input): array
    {
        $info = Activity::getInfo();
        if (
            $info->isInWorkflow()
            || $info->workflowExecution !== null
            || $info->workflowType !== null
            || $info->activityRunId === ''
            || $info->namespace !== 'default'
            || $info->workflowNamespace !== 'default'
        ) {
            throw new \RuntimeException('Standalone ActivityInfo is inconsistent.');
        }

        return [
            'input' => $input,
            'activityId' => $info->id,
            'runId' => $info->activityRunId,
            'namespace' => $info->namespace,
            'taskQueue' => $info->taskQueue,
        ];
    }

    #[ActivityMethod]
    public function fail(string $message): never
    {
        throw new \RuntimeException("standalone-failure:{$message}");
    }

    #[ActivityMethod]
    public function completeLater(): void
    {
        $info = Activity::getInfo();
        $this->state->deferred = [
            'activityId' => $info->id,
            'runId' => $info->activityRunId,
        ];
        Activity::doNotCompleteOnReturn();
    }

    #[ActivityMethod]
    public function linger(): string
    {
        $id = Activity::getInfo()->id;
        $this->state->started[$id] = true;

        try {
            for ($i = 0; $i < 600; $i++) {
                delay(50);
                Activity::heartbeat($i);
            }
        } catch (ActivityCanceledException $e) {
            $this->state->canceled[$id] = true;
            throw $e;
        }

        return 'unexpected-completion';
    }
}

function standaloneAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function standaloneFailureChainContains(\Throwable $error, string $message): bool
{
    do {
        if (\str_contains($error->getMessage(), $message)) {
            return true;
        }
        $error = $error->getPrevious();
    } while ($error !== null);

    return false;
}

function standaloneFailureChainHas(\Throwable $error, string $class): bool
{
    do {
        if ($error instanceof $class) {
            return true;
        }
        $error = $error->getPrevious();
    } while ($error !== null);

    return false;
}

$address = $argv[1] ?? '127.0.0.1:7233';
if (!\extension_loaded('temporal')) {
    \fwrite(\STDERR, "FAIL: temporal extension not loaded\n");
    exit(1);
}

$taskQueue = 'trueasync-standalone-' . \bin2hex(\random_bytes(4));
$prefix = 'trueasync-standalone-' . \bin2hex(\random_bytes(5));
$state = new \stdClass();
$state->deferred = null;
$state->started = [];
$state->canceled = [];

try {
    $result = await(spawn(static function () use ($address, $taskQueue, $prefix, $state): array {
        $workflowClient = WorkflowClient::create(
            TrueAsyncServiceClient::fromCore(new Connection($address)),
        );
        $activities = $workflowClient->newActivityClient();
        $factory = WorkerFactory::create(
            client: $workflowClient,
            connection: new Connection($address),
        );
        $factory
            ->newWorker($taskQueue)
            ->registerActivityImplementations(new StandaloneActivityE2E($state));

        $workerLoop = spawn(static fn(): int => $factory->run());

        try {
            $noRetry = RetryOptions::new()->withMaximumAttempts(1);
            $options = static fn(string $id): StandaloneActivityOptions =>
                StandaloneActivityOptions::new($id, $taskQueue)
                    ->withScheduleToCloseTimeout(30)
                    ->withStartToCloseTimeout(20)
                    ->withHeartbeatTimeout(1)
                    ->withRetryOptions($noRetry)
                    ->withSummary("E2E {$id}")
                    ->withDetails('TrueAsync standalone Activity integration test');

            $successId = "{$prefix}-success";
            $success = $activities->start(
                'StandaloneActivityE2E.inspect',
                $options($successId),
                'payload',
            );
            standaloneAssert($success->getRunId() !== null && $success->getRunId() !== '', 'Start returned no run ID.');

            $description = $success->describe();
            standaloneAssert($description->activityId === $successId, 'Describe returned the wrong Activity ID.');
            standaloneAssert($description->runId === $success->getRunId(), 'Describe returned the wrong run ID.');
            standaloneAssert($description->getInput('string') === 'payload', 'Describe did not decode input.');
            standaloneAssert($description->getSummary() === "E2E {$successId}", 'Describe did not decode summary.');

            $successResult = $success->getResult('array');
            standaloneAssert($successResult['input'] === 'payload', 'Activity returned the wrong input.');
            standaloneAssert($successResult['activityId'] === $successId, 'Worker saw the wrong Activity ID.');
            standaloneAssert($successResult['runId'] === $success->getRunId(), 'Worker saw the wrong Activity run ID.');
            standaloneAssert($successResult['namespace'] === 'default', 'Worker saw the wrong namespace.');
            standaloneAssert($successResult['taskQueue'] === $taskQueue, 'Worker saw the wrong task queue.');

            $reattached = $activities->getHandle($successId, $success->getRunId());
            standaloneAssert(
                $reattached->getResult('array') === $successResult,
                'Reattached handle returned a different result.',
            );

            $failureId = "{$prefix}-failure";
            $failed = $activities->start(
                'StandaloneActivityE2E.fail',
                $options($failureId),
                'expected',
            );
            try {
                $failed->getResult();
                throw new \RuntimeException('Failing Activity unexpectedly completed.');
            } catch (ActivityExecutionFailedException $error) {
                standaloneAssert(
                    standaloneFailureChainContains($error, 'standalone-failure:expected'),
                    'Activity failure cause was not preserved.',
                );
            }

            $deferredId = "{$prefix}-deferred";
            $deferred = $activities->start(
                'StandaloneActivityE2E.completeLater',
                $options($deferredId),
            );
            for ($i = 0; $i < 100 && $state->deferred === null; $i++) {
                delay(20);
            }
            standaloneAssert($state->deferred !== null, 'Deferred Activity did not start.');
            standaloneAssert($state->deferred['activityId'] === $deferredId, 'Deferred Activity ID mismatch.');
            standaloneAssert($state->deferred['runId'] === $deferred->getRunId(), 'Deferred run ID mismatch.');

            $workflowClient
                ->newActivityCompletionClient()
                ->withContext(new ActivitySerializationContext(
                    namespace: 'default',
                    activityType: 'StandaloneActivityE2E.completeLater',
                    taskQueue: $taskQueue,
                ))
                ->complete(
                    '',
                    $deferred->getRunId(),
                    $deferredId,
                    'completed-externally',
                );
            standaloneAssert(
                $deferred->getResult('string') === 'completed-externally',
                'Standalone asynchronous completion returned the wrong result.',
            );

            $cancelId = "{$prefix}-cancel";
            $cancel = $activities->start(
                'StandaloneActivityE2E.linger',
                $options($cancelId),
            );
            for ($i = 0; $i < 200 && !($state->started[$cancelId] ?? false); $i++) {
                delay(20);
            }
            standaloneAssert($state->started[$cancelId] ?? false, 'Cancelable Activity did not start.');
            $cancel->cancel('E2E cancellation');

            try {
                $cancel->getResult();
                throw new \RuntimeException('Canceled Activity unexpectedly completed.');
            } catch (ActivityExecutionFailedException $error) {
                standaloneAssert(
                    standaloneFailureChainHas($error, CanceledFailure::class),
                    'Cancellation was not mapped to CanceledFailure.',
                );
            }
            standaloneAssert($state->canceled[$cancelId] ?? false, 'Activity did not observe cancellation.');

            $listed = false;
            $count = 0;
            for ($i = 0; $i < 50 && !$listed; $i++) {
                foreach ($activities->list('', pageSize: 100) as $execution) {
                    if ($execution->activityId === $successId) {
                        $listed = true;
                        break;
                    }
                }
                $count = $activities->count()->count;
                if (!$listed || $count < 4) {
                    delay(100);
                }
            }
            standaloneAssert($listed, 'Completed Activity was not visible in list results.');
            standaloneAssert($count >= 4, 'Count did not include the standalone Activity executions.');

            return [
                'successId' => $successId,
                'runId' => $success->getRunId(),
                'visibleCount' => $count,
            ];
        } finally {
            $factory->shutdown();
            await($workerLoop);
        }
    }));
} catch (\Throwable $error) {
    \fwrite(\STDERR, "FAIL: {$error}\n");
    exit(1);
}

\fwrite(
    \STDOUT,
    "PASS: standalone Activity {$result['successId']} run {$result['runId']}; "
    . "visibility count {$result['visibleCount']}\n",
);
exit(0);
