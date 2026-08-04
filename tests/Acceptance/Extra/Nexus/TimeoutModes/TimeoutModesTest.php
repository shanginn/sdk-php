<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus\TimeoutModes;

use Carbon\CarbonInterval;
use PHPUnit\Framework\Attributes\Test;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Failure\NexusOperationFailure;
use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\WorkflowHandle;
use Temporal\Tests\Acceptance\App\Attribute\Worker;
use Temporal\Tests\Acceptance\App\Runtime\State;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusEndpoints;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusHistoryAssertions;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusWorkerOptions;
use Temporal\Worker\WorkerOptions;
use Temporal\Workflow;
use Temporal\Workflow\NexusOperationOptions;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/**
 * Live coverage for the two independently configurable Nexus timeout phases.
 *
 * Schedule-to-Start targets a task queue that intentionally has no Nexus
 * poller. Start-to-Close uses a Workflow-backed operation that is accepted and
 * started before its backing Workflow can complete.
 */
#[Worker(options: [self::class, 'workerOptions'])]
final class TimeoutModesTest extends TestCase
{
    use NexusHistoryAssertions;

    public static function workerOptions(): WorkerOptions
    {
        return NexusWorkerOptions::default();
    }

    #[Test]
    public function scheduleToStartExpiresWhileNoNexusWorkerPollsEndpointQueue(
        State $state,
        WorkflowClientInterface $client,
        NexusEndpoints $endpoints,
    ): void {
        $unpolledTaskQueue = 'nexus-timeout-modes-unpolled-' . \bin2hex(\random_bytes(8));
        $endpoint = $endpoints->register(
            $state->namespace,
            $unpolledTaskQueue,
            'nexus-timeout-schedule-to-start',
        );

        $caller = $client->newUntypedWorkflowStub(
            'Extra_Nexus_TimeoutModes_ScheduleToStartCaller',
            WorkflowOptions::new()
                ->withTaskQueue(__NAMESPACE__)
                ->withWorkflowExecutionTimeout(CarbonInterval::seconds(30)),
        );

        $client->start($caller, $endpoint->name);

        self::assertSame(
            self::expectedTimeout(TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_START),
            $caller->getResult('array', timeout: 20),
        );

        $history = $client->getWorkflowHistory($caller->getExecution())->getHistory();
        self::assertSame(
            1,
            self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED),
        );
        self::assertSame(
            0,
            self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED),
            'An operation with no handler poller must not reach the Started phase.',
        );
        self::assertSame(
            1,
            self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT),
        );
    }

    #[Test]
    public function startToCloseExpiresAfterWorkflowBackedOperationStarts(
        State $state,
        WorkflowClientInterface $client,
        NexusEndpoints $endpoints,
    ): void {
        $endpoint = $endpoints->register(
            $state->namespace,
            __NAMESPACE__,
            'nexus-timeout-start-to-close',
        );

        $caller = $client->newUntypedWorkflowStub(
            'Extra_Nexus_TimeoutModes_StartToCloseCaller',
            WorkflowOptions::new()
                ->withTaskQueue(__NAMESPACE__)
                ->withWorkflowExecutionTimeout(CarbonInterval::seconds(30)),
        );

        $client->start($caller, $endpoint->name);

        self::assertSame(
            self::expectedTimeout(TimeoutType::TIMEOUT_TYPE_START_TO_CLOSE),
            $caller->getResult('array', timeout: 20),
        );

        $history = $client->getWorkflowHistory($caller->getExecution())->getHistory();
        self::assertSame(
            1,
            self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED),
        );
        self::assertSame(
            1,
            self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED),
            'Start-to-Close must begin only after the async handler accepted the operation.',
        );
        self::assertSame(
            1,
            self::countEvents($history, EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT),
        );
    }

    /**
     * @return array{failure: class-string, cause: class-string, timeoutType: int}
     */
    private static function expectedTimeout(int $timeoutType): array
    {
        return [
            'failure' => NexusOperationFailure::class,
            'cause' => TimeoutFailure::class,
            'timeoutType' => $timeoutType,
        ];
    }
}

#[Service(name: 'TimeoutModesService')]
final class TimeoutModesService
{
    #[Operation(output: 'string')]
    public function longRunning(string $input): WorkflowHandle
    {
        return WorkflowHandle::fromWorkflowMethod(
            TimeoutModesHandlerWorkflow::class,
            WorkflowOptions::new()->withWorkflowId(Nexus::getStartDetails()->requestId),
            $input,
        );
    }
}

#[WorkflowInterface]
final class TimeoutModesHandlerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_TimeoutModes_Handler')]
    public function run(string $input): string
    {
        Workflow::timer(CarbonInterval::seconds(30));
        return "should-not-complete:{$input}";
    }
}

#[WorkflowInterface]
final class ScheduleToStartCallerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_TimeoutModes_ScheduleToStartCaller')]
    public function run(string $endpoint): array
    {
        $stub = Workflow::newNexusServiceStub(
            TimeoutModesService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(10))
                ->withScheduleToStartTimeout(CarbonInterval::seconds(1)),
        );

        try {
            $stub->longRunning('schedule-to-start');
        } catch (NexusOperationFailure $failure) {
            $cause = $failure->getPrevious();
            return [
                'failure' => $failure::class,
                'cause' => $cause === null ? 'null' : $cause::class,
                'timeoutType' => $cause instanceof TimeoutFailure ? $cause->getTimeoutType() : -1,
            ];
        }

        return [
            'failure' => 'none',
            'cause' => 'none',
            'timeoutType' => -1,
        ];
    }
}

#[WorkflowInterface]
final class StartToCloseCallerWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_TimeoutModes_StartToCloseCaller')]
    public function run(string $endpoint): array
    {
        $stub = Workflow::newNexusServiceStub(
            TimeoutModesService::class,
            NexusOperationOptions::new()
                ->withEndpoint($endpoint)
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(20))
                ->withStartToCloseTimeout(CarbonInterval::seconds(2)),
        );

        try {
            $stub->longRunning('start-to-close');
        } catch (NexusOperationFailure $failure) {
            $cause = $failure->getPrevious();
            return [
                'failure' => $failure::class,
                'cause' => $cause === null ? 'null' : $cause::class,
                'timeoutType' => $cause instanceof TimeoutFailure ? $cause->getTimeoutType() : -1,
            ];
        }

        return [
            'failure' => 'none',
            'cause' => 'none',
            'timeoutType' => -1,
        ];
    }
}
