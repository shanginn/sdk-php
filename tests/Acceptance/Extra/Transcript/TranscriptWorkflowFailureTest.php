<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Transcript\TranscriptWorkflowFailure;

use Temporal\Api\Enums\V1\EventType;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Exception\Client\WorkflowFailedException;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Tests\Acceptance\App\Attribute\Stub;
use Temporal\Testing\Transcript\TranscriptLine;
use Temporal\Testing\Transcript\TranscriptSection;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

final class TranscriptWorkflowFailureTest extends TestCase
{
    public function testWorkflowFailureCapturedWithHistory(
        #[Stub('Extra_Transcript_TranscriptWorkflowFailure_run')]
        WorkflowStubInterface $stub,
        WorkflowClientInterface $client,
    ): void {
        $thrown = null;
        try {
            $stub->getResult('string');
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }
        self::assertInstanceOf(WorkflowFailedException::class, $thrown);

        $lines = $this->readCurrentTestTranscript();

        $executeMarkers = \array_values(\array_filter(
            $lines,
            static fn(TranscriptLine $line): bool => $line->section === TranscriptSection::META
                && ($line->attributes['event'] ?? null) === 'workflow_execute_start',
        ));
        self::assertCount(1, $executeMarkers, 'Expected exactly one workflow_execute_start META');
        self::assertSame('Extra_Transcript_TranscriptWorkflowFailure_run', $executeMarkers[0]->attributes['workflow_type']);
        self::assertSame($stub->getExecution()->getID(), $executeMarkers[0]->attributes['workflow_id']);

        $failed = false;
        foreach ($client->getWorkflowHistory($stub->getExecution()) as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED) {
                $failed = true;
                self::assertStringContainsString(
                    'workflow-boom',
                    $event->getWorkflowExecutionFailedEventAttributes()?->getFailure()?->getMessage() ?? '',
                );
            }
        }
        self::assertTrue($failed, 'Expected the native worker failure in Temporal history');

        self::assertSame(
            [],
            \array_values(\array_filter(
                $lines,
                static fn(TranscriptLine $line): bool => $line->section === TranscriptSection::WIRE_OUTBOUND,
            )),
            'The TrueAsync worker must not emit legacy RoadRunner wire frames',
        );
    }
}

#[WorkflowInterface]
class FailingWorkflow
{
    #[WorkflowMethod(name: 'Extra_Transcript_TranscriptWorkflowFailure_run')]
    public function run(): \Generator
    {
        yield;
        throw new ApplicationFailure('workflow-boom', 'TestWorkflowFailure', false);
    }
}
