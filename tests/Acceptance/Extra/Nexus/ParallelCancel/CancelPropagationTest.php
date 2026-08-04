<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus\ParallelCancel;

use Carbon\CarbonInterval;
use PHPUnit\Framework\Attributes\Test;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\NexusOperationFailure;
use Temporal\Nexus\Attribute\AsyncOperation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\WorkflowHandle;
use Temporal\Tests\Acceptance\App\Attribute\Stub;
use Temporal\Tests\Acceptance\App\Attribute\Worker;
use Temporal\Tests\Acceptance\App\Runtime\State;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusEndpoints;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusHistoryAssertions;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusWorkerOptions;
use Temporal\Worker\WorkerOptions;
use Temporal\Workflow;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\NexusOperationOptions;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/**
 * Scope cancel over Workflow::all of N async Nexus ops fans out per-sibling.
 */
#[Worker(options: [self::class, 'workerOptions'])]
class CancelPropagationTest extends TestCase
{
    use NexusHistoryAssertions;

    public static function workerOptions(): WorkerOptions
    {
        return NexusWorkerOptions::default();
    }

    #[Test]
    public function cancellingScopeWithPromiseAllCancelsAllSiblings(
        State $state,
        WorkflowClientInterface $client,
        NexusEndpoints $endpoints,
        #[Stub('Extra_Nexus_ParallelCancel_Bootstrap')]
        WorkflowStubInterface $bootstrapStub,
    ): void {
        $bootstrapStub->getResult('string');

        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'nexus-cancel-prop');

        $stub = $client->newUntypedWorkflowStub(
            'Extra_Nexus_ParallelCancel_Caller',
            WorkflowOptions::new()
                ->withTaskQueue(__NAMESPACE__)
                ->withWorkflowExecutionTimeout(CarbonInterval::seconds(60)),
        );

        $client->start($stub, $endpoint->name);

        self::assertSame('cancelled', $stub->getResult('string'));

        $history = $client->getWorkflowHistory($stub->getExecution())->getHistory();
        $scheduled = self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $cancelRequested = self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED);

        self::assertSame(
            3,
            $scheduled,
            'All three siblings must reach the schedule event before cancel.',
        );
        self::assertSame(
            3,
            $cancelRequested,
            'Cancel must fan out to every sibling — proves Scope::onRequest registers per-promise onCancel.',
        );

        $canceled = self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED);
        self::assertSame(
            3,
            $canceled,
            'Every sibling must reach the terminal CANCELED event — the handler re-raises the '
            . 'CanceledFailure so the operation closes as CANCELED rather than COMPLETED.',
        );

        $completed = self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED);
        self::assertSame(
            0,
            $completed,
            'No sibling may close as COMPLETED once cancellation propagated.',
        );
    }
}

#[Service(name: 'CancelPropagationService')]
class CancelPropagationService
{
    #[AsyncOperation(output: 'string')]
    public function longRunning(string $input): WorkflowHandle
    {
        return WorkflowHandle::fromWorkflowMethod(
            CancelPropagationHandlerWorkflow::class,
            WorkflowOptions::new()->withWorkflowId(Nexus::getStartDetails()->requestId),
            $input,
        );
    }
}

#[WorkflowInterface]
class CancelPropagationHandlerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_ParallelCancel_Handler')]
    public function handle(string $input)
    {
        Workflow::timer(CarbonInterval::seconds(45));
        return "completed:{$input}";
    }
}

#[WorkflowInterface]
class CancelPropagationCallerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_ParallelCancel_Caller')]
    public function run(string $endpoint)
    {
        $stub = Workflow::newNexusServiceStub(
            CancelPropagationService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(60))
                ->withCancellationType(NexusOperationCancellationType::WaitCompleted),
        );

        $operations = [];
        $combined = Workflow::async(static function () use ($stub, &$operations): array {
            $operations = [
                Workflow::async(static fn() => $stub->longRunning('a')),
                Workflow::async(static fn() => $stub->longRunning('b')),
                Workflow::async(static fn() => $stub->longRunning('c')),
            ];

            return Workflow::all($operations);
        });

        Workflow::timer(CarbonInterval::seconds(NexusWorkerOptions::PRE_CANCEL_TIMER_SECONDS));
        $combined->cancel();

        $outcome = 'unexpected-no-failure';
        try {
            $combined->await();
        } catch (NexusOperationFailure $e) {
            $outcome = $e->getPrevious() instanceof CanceledFailure
                ? 'cancelled'
                : 'unexpected-cause:' . ($e->getPrevious()?->getMessage() ?? 'null');
        } catch (CanceledFailure) {
            $outcome = 'cancelled';
        }

        // Drain every sibling so all terminal events land before the caller closes.
        foreach ($operations as $operation) {
            try {
                $operation->await();
            } catch (\Throwable) {
            }
        }

        return $outcome;
    }
}

#[WorkflowInterface]
class ParallelCancelBootstrapWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_ParallelCancel_Bootstrap')]
    public function run(): string
    {
        return 'ready';
    }
}
