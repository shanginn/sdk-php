<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus\Timeout;

use Carbon\CarbonInterval;
use PHPUnit\Framework\Attributes\Test;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Failure\NexusOperationFailure;
use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Nexus\Attribute\AsyncOperation;
use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Handler\MethodCancellationListenerInterface;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\WorkflowHandle;
use Temporal\Tests\Acceptance\App\Attribute\Worker;
use Temporal\Tests\Acceptance\App\Runtime\State;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusEndpoints;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusHttpClient;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusWorkerOptions;
use Temporal\Worker\WorkerOptions;
use Temporal\Workflow;
use Temporal\Workflow\NexusOperationOptions;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/**
 * P2 #10–11 — `scheduleToCloseTimeout` enforcement on caller side.
 *
 * Two scenarios:
 *   - sync handler runs longer than the timeout → caller catches a
 *     {@see NexusOperationFailure} whose cause is {@see TimeoutFailure}.
 *   - async handler workflow runs longer than the timeout → same.
 *
 * P2 #12 ("retry within timeout") is deferred — `NexusOperationOptions` does
 * not currently expose a retry policy, so retry semantics are server-driven
 * and not under direct caller control. Tracked as a gap in nexus_plan.md.
 */
#[Worker(options: [self::class, 'workerOptions'])]
class TimeoutTest extends TestCase
{
    public static function workerOptions(): WorkerOptions
    {
        return NexusWorkerOptions::default();
    }

    #[Test]
    public function syncOperationTimesOutOnCaller(
        State $state,
        WorkflowClientInterface $client,
        NexusEndpoints $endpoints,
    ): void {
        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'nexus-timeout-sync');

        $stub = $client->newUntypedWorkflowStub(
            'Extra_Nexus_Timeout_SyncCaller',
            WorkflowOptions::new()
                ->withTaskQueue(__NAMESPACE__)
                ->withWorkflowExecutionTimeout(CarbonInterval::seconds(20)),
        );

        $client->start($stub, $endpoint->name);

        self::assertSame('ok', $stub->getResult('string'));
    }

    #[Test]
    public function asyncOperationTimesOutOnCaller(
        State $state,
        WorkflowClientInterface $client,
        NexusEndpoints $endpoints,
    ): void {
        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'nexus-timeout-async');

        $stub = $client->newUntypedWorkflowStub(
            'Extra_Nexus_Timeout_AsyncCaller',
            WorkflowOptions::new()
                ->withTaskQueue(__NAMESPACE__)
                ->withWorkflowExecutionTimeout(CarbonInterval::seconds(20)),
        );

        $client->start($stub, $endpoint->name);

        self::assertSame('ok', $stub->getResult('string'));
    }

    #[Test]
    public function syncHandlerObservesRequestCancellationAcrossNativeWorkerBoundary(
        State $state,
        NexusEndpoints $endpoints,
        NexusHttpClient $http,
    ): void {
        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'nexus-timeout-method-cancel');
        $marker = \sys_get_temp_dir() . '/temporal-nexus-method-cancel-' . \bin2hex(\random_bytes(8)) . '.json';

        try {
            [$status] = $http->post(
                $endpoint,
                'TimeoutSyncService',
                'waitForRequestCancellation',
                $marker,
                ['Request-Timeout' => '1s'],
            );

            self::assertSame(520, $status, 'the frontend must report the handler request deadline as upstream timeout');
            $markerDeadline = \microtime(true) + 5.0;
            while (!\is_file($marker) && \microtime(true) < $markerDeadline) {
                \usleep(50_000);
            }
            self::assertFileExists($marker, 'the still-running PHP handler must observe Go context cancellation');

            $observed = \json_decode((string) \file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($observed);
            self::assertSame(true, $observed['cancelled'] ?? null);
            self::assertSame(true, $observed['listenerCalled'] ?? null);
            self::assertIsString($observed['reason'] ?? null);
            self::assertNotSame('', $observed['reason']);
        } finally {
            @\unlink($marker);
        }
    }
}

// ── Sync service: handler sleeps past the caller's timeout ─────────

#[Service(name: 'TimeoutSyncService')]
class TimeoutSyncService
{
    #[Operation]
    public function slowSync(string $input): string
    {
        // Suspend this handler just past the caller's 2s timeout while the
        // native pollers and unrelated operations remain runnable.
        \Async\delay(5_000);
        return "should-not-reach:{$input}";
    }

    #[Operation]
    public function waitForRequestCancellation(string $marker): string
    {
        $context = Nexus::getCurrentOperationContext();
        $listener = new class($marker, $context) implements MethodCancellationListenerInterface {
            public function __construct(
                private readonly string $marker,
                private readonly \Temporal\Nexus\Handler\OperationContext $context,
            ) {}

            public function cancelled(): void
            {
                $temporary = $this->marker . '.' . \getmypid() . '.tmp';
                \file_put_contents(
                    $temporary,
                    \json_encode([
                        'cancelled' => $this->context->isMethodCancelled(),
                        'listenerCalled' => true,
                        'reason' => $this->context->getMethodCancellationReason(),
                    ], \JSON_THROW_ON_ERROR),
                    \LOCK_EX,
                );
                \rename($temporary, $this->marker);
            }
        };
        $context->addMethodCancellationListener($listener);

        // Remain in flight while yielding to the native poller. The listener is
        // invoked before the runtime cancels this handler coroutine.
        \Async\delay(10_000);
        return 'cancel-not-observed';
    }
}

#[WorkflowInterface]
class TimeoutSyncCallerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_Timeout_SyncCaller')]
    public function run(string $endpoint)
    {
        $stub = Workflow::newNexusServiceStub(
            TimeoutSyncService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(2)),
        );

        try {
            yield $stub->slowSync('payload');
        } catch (NexusOperationFailure $e) {
            $cause = $e->getPrevious();
            if (!$cause instanceof TimeoutFailure) {
                $causeName = $cause === null ? 'null' : $cause::class;
                return "wrong-cause:{$causeName}";
            }
            return 'ok';
        }

        return 'unexpected:no-exception';
    }
}

// ── Async service: handler workflow sleeps past the caller's timeout ─

#[Service(name: 'TimeoutAsyncService')]
class TimeoutAsyncService
{
    #[AsyncOperation(output: 'string')]
    public function slowAsync(string $input): WorkflowHandle
    {
        return WorkflowHandle::fromWorkflowMethod(
            SlowHandlerWorkflow::class,
            WorkflowOptions::new()->withWorkflowId(Nexus::getStartDetails()->requestId),
            $input,
        );
    }
}

#[WorkflowInterface]
class SlowHandlerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_Timeout_SlowHandler')]
    public function handle(string $input)
    {
        // Sleep well past the caller's 3s timeout.
        yield Workflow::timer(CarbonInterval::seconds(15));
        return "should-not-reach:{$input}";
    }
}

#[WorkflowInterface]
class TimeoutAsyncCallerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_Timeout_AsyncCaller')]
    public function run(string $endpoint)
    {
        $stub = Workflow::newNexusServiceStub(
            TimeoutAsyncService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(3)),
        );

        try {
            yield $stub->slowAsync('payload');
        } catch (NexusOperationFailure $e) {
            $cause = $e->getPrevious();
            if (!$cause instanceof TimeoutFailure) {
                $causeName = $cause === null ? 'null' : $cause::class;
                return "wrong-cause:{$causeName}";
            }
            return 'ok';
        }

        return 'unexpected:no-exception';
    }
}
