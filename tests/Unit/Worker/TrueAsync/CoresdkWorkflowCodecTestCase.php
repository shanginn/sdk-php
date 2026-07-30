<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\TrueAsync;

use Coresdk\Common\NamespacedWorkflowExecution;
use Coresdk\Nexus\NexusOperationCancellationType as CoresdkNexusCancellationType;
use Coresdk\Nexus\NexusOperationResult;
use Coresdk\WorkflowActivation\InitializeWorkflow;
use Coresdk\WorkflowActivation\NotifyHasPatch;
use Coresdk\WorkflowActivation\RemoveFromCache;
use Coresdk\WorkflowActivation\RemoveFromCache\EvictionReason;
use Coresdk\WorkflowActivation\ResolveChildWorkflowExecutionStart;
use Coresdk\WorkflowActivation\ResolveChildWorkflowExecutionStartSuccess;
use Coresdk\WorkflowActivation\ResolveNexusOperation;
use Coresdk\WorkflowActivation\ResolveNexusOperationStart;
use Coresdk\WorkflowActivation\WorkflowActivation;
use Coresdk\WorkflowActivation\WorkflowActivationJob;
use Coresdk\WorkflowCompletion\WorkflowActivationCompletion;
use Google\Protobuf\Duration;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\Priority;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\Api\Common\V1\SearchAttributes;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\VersioningBehavior;
use Temporal\Api\Enums\V1\SuggestContinueAsNewReason;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\Header;
use Temporal\Internal\Transport\Request\ExecuteChildWorkflow;
use Temporal\Internal\Transport\Request\ExecuteNexusOperation;
use Temporal\Internal\Transport\Request\ContinueAsNew;
use Temporal\Internal\Transport\Request\GetChildWorkflowExecution;
use Temporal\Internal\Transport\Request\GetNexusOperationStarted;
use Temporal\Internal\Workflow\NexusStartEnvelope;
use Temporal\Worker\Transport\Command\Client\Request;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\TrueAsync\CoresdkWorkflowCodec;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\ContinueAsNewSuggestedReason;
use Temporal\Workflow\ContinueAsNewVersioningBehavior;
use Temporal\Workflow\WorkflowExecution as SdkWorkflowExecution;

final class CoresdkWorkflowCodecTestCase extends TestCase
{
    public function testActivationCarriesCurrentContinueAsNewAndDeploymentMetadata(): void
    {
        $codec = new CoresdkWorkflowCodec(DataConverter::createDefault());
        $commands = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('run-metadata')
                ->setHistoryLength(42)
                ->setHistorySizeBytes(1024)
                ->setContinueAsNewSuggested(true)
                ->setSuggestContinueAsNewReasons([
                    SuggestContinueAsNewReason::SUGGEST_CONTINUE_AS_NEW_REASON_HISTORY_SIZE_TOO_LARGE,
                    SuggestContinueAsNewReason::SUGGEST_CONTINUE_AS_NEW_REASON_TOO_MANY_UPDATES,
                ])
                ->setTargetWorkerDeploymentVersionChanged(true)
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('PinnedWorkflow')
                            ->setWorkflowId('workflow-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue'],
        ));

        self::assertCount(1, $commands);
        $tick = $commands[0]->getTickInfo();
        self::assertSame(42, $tick->historyLength);
        self::assertSame(1024, $tick->historySize);
        self::assertTrue($tick->continueAsNewSuggested);
        self::assertSame([
            ContinueAsNewSuggestedReason::HistorySizeTooLarge,
            ContinueAsNewSuggestedReason::TooManyUpdates,
        ], $tick->continueAsNewSuggestedReasons);
        self::assertTrue($tick->targetWorkerDeploymentVersionChanged);
    }

    public function testCodecEmitsWorkflowInfoUpdateForActivationWithoutRuntimeCommand(): void
    {
        $codec = $this->initializedCodec(DataConverter::createDefault(), 'patch-run');
        $commands = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('patch-run')
                ->setHistoryLength(7)
                ->setTargetWorkerDeploymentVersionChanged(true)
                ->setJobs([
                    (new WorkflowActivationJob())->setNotifyHasPatch(
                        (new NotifyHasPatch())->setPatchId('existing-patch'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue'],
        ));

        self::assertCount(1, $commands);
        self::assertInstanceOf(ServerRequest::class, $commands[0]);
        self::assertSame('UpdateWorkflowInfo', $commands[0]->getName());
        self::assertSame(7, $commands[0]->getTickInfo()->historyLength);
        self::assertTrue($commands[0]->getTickInfo()->targetWorkerDeploymentVersionChanged);
    }

    public function testContinueAsNewInitialVersioningBehaviorIsReplayStable(): void
    {
        $converter = DataConverter::createDefault();

        $encode = function (bool $isReplaying) use ($converter): string {
            $codec = $this->initializedCodec(
                $converter,
                $isReplaying ? 'replay-run' : 'live-run',
                $isReplaying,
            );
            $codec->stage(new ContinueAsNew(
                'PinnedWorkflow',
                EncodedValues::fromValues(['next-run'], $converter),
                [
                    'TaskQueueName' => 'versioned-queue',
                    'WorkflowRunTimeout' => 0,
                    'WorkflowTaskTimeout' => 0,
                    'InitialVersioningBehavior' => ContinueAsNewVersioningBehavior::UseRampingVersion->value,
                ],
                Header::empty(),
            ));

            $command = self::completion($codec)
                ->getSuccessful()
                ->getCommands()[0]
                ->getContinueAsNewWorkflowExecution();

            self::assertSame('PinnedWorkflow', $command->getWorkflowType());
            self::assertSame('versioned-queue', $command->getTaskQueue());
            self::assertSame(
                ContinueAsNewVersioningBehavior::UseRampingVersion->value,
                $command->getInitialVersioningBehavior(),
            );

            return $command->serializeToString();
        };

        self::assertSame($encode(false), $encode(true));
    }

    public function testWorkflowVersioningBehaviorIsReportedToCore(): void
    {
        $codec = new CoresdkWorkflowCodec(
            DataConverter::createDefault(),
            static fn(string $taskQueue, string $workflowType): int => match ([$taskQueue, $workflowType]) {
                ['queue', 'PinnedWorkflow'] => VersioningBehavior::VERSIONING_BEHAVIOR_PINNED,
                default => VersioningBehavior::VERSIONING_BEHAVIOR_UNSPECIFIED,
            },
        );

        \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('run-versioned')
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('PinnedWorkflow')
                            ->setWorkflowId('workflow-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue'],
        ));

        $completion = new \Coresdk\WorkflowCompletion\WorkflowActivationCompletion();
        $completion->mergeFromString($codec->encodeStaged());

        self::assertSame(
            VersioningBehavior::VERSIONING_BEHAVIOR_PINNED,
            $completion->getSuccessful()->getVersioningBehavior(),
        );
    }

    public function testEvictionMetadataIsCapturedAndDrained(): void
    {
        $activation = (new WorkflowActivation())
            ->setRunId('run-1')
            ->setJobs([
                (new WorkflowActivationJob())->setRemoveFromCache(
                    (new RemoveFromCache())
                        ->setReason(EvictionReason::NONDETERMINISM)
                        ->setMessage('timer command does not match history'),
                ),
            ]);

        $codec = new CoresdkWorkflowCodec(DataConverter::createDefault());
        $commands = \iterator_to_array($codec->decode(
            $activation->serializeToString(),
            ['taskQueue' => 'replay'],
        ));

        self::assertCount(1, $commands);
        self::assertInstanceOf(ServerRequest::class, $commands[0]);
        self::assertSame('DestroyWorkflow', $commands[0]->getName());
        self::assertSame([[
            'runId' => 'run-1',
            'reason' => EvictionReason::NONDETERMINISM,
            'reasonName' => 'NONDETERMINISM',
            'message' => 'timer command does not match history',
        ]], $codec->drainEvictions());
        self::assertSame([], $codec->drainEvictions());
    }

    public function testWorkflowStartCarriesCoreExecutionContext(): void
    {
        $converter = DataConverter::createDefault();
        $searchAttribute = $converter->toPayload('search-value');
        $searchAttribute->getMetadata()['type'] = 'Keyword';

        $activation = (new WorkflowActivation())
            ->setRunId('current-run')
            ->setJobs([
                (new WorkflowActivationJob())->setInitializeWorkflow(
                    (new InitializeWorkflow())
                        ->setWorkflowType('ExampleWorkflow')
                        ->setWorkflowId('workflow-id')
                        ->setArguments([$converter->toPayload('argument')])
                        ->setHeaders(['trace-id' => $converter->toPayload('trace-value')])
                        ->setParentWorkflowInfo(
                            (new NamespacedWorkflowExecution())
                                ->setNamespace('parent-namespace')
                                ->setWorkflowId('parent-id')
                                ->setRunId('parent-run'),
                        )
                        ->setRootWorkflow(
                            (new WorkflowExecution())
                                ->setWorkflowId('root-id')
                                ->setRunId('root-run'),
                        )
                        ->setWorkflowExecutionTimeout((new Duration())->setSeconds(30))
                        ->setWorkflowRunTimeout((new Duration())->setSeconds(20))
                        ->setWorkflowTaskTimeout((new Duration())->setSeconds(10))
                        ->setContinuedFromExecutionRunId('previous-run')
                        ->setFirstExecutionRunId('first-run')
                        ->setAttempt(2)
                        ->setCronSchedule('0 * * * *')
                        ->setLastCompletionResult(
                            (new Payloads())->setPayloads([$converter->toPayload('previous-result')]),
                        )
                        ->setSearchAttributes(
                            (new SearchAttributes())->setIndexedFields([
                                'CustomKeywordField' => $searchAttribute,
                            ]),
                        )
                        ->setRetryPolicy(
                            (new RetryPolicy())
                                ->setInitialInterval((new Duration())->setSeconds(1))
                                ->setBackoffCoefficient(3)
                                ->setMaximumInterval((new Duration())->setSeconds(120))
                                ->setMaximumAttempts(10),
                        )
                        ->setPriority(
                            (new Priority())
                                ->setPriorityKey(2)
                                ->setFairnessKey('tenant')
                                ->setFairnessWeight(1.5),
                        ),
                ),
            ]);

        $codec = new CoresdkWorkflowCodec($converter);
        $commands = \iterator_to_array($codec->decode(
            $activation->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'application'],
        ));

        self::assertCount(1, $commands);
        self::assertInstanceOf(ServerRequest::class, $commands[0]);
        self::assertSame('StartWorkflow', $commands[0]->getName());

        $options = $commands[0]->getOptions();
        self::assertSame(1, $options['lastCompletion']);
        self::assertSame([
            'ID' => 'workflow-id',
            'RunID' => 'current-run',
        ], $options['info']['WorkflowExecution']);
        self::assertSame('application', $options['info']['Namespace']);
        self::assertSame(30_000_000_000, $options['info']['WorkflowExecutionTimeout']);
        self::assertSame(20_000_000_000, $options['info']['WorkflowRunTimeout']);
        self::assertSame(10_000_000_000, $options['info']['WorkflowTaskTimeout']);
        self::assertSame('previous-run', $options['info']['ContinuedExecutionRunID']);
        self::assertSame('first-run', $options['info']['FirstRunID']);
        self::assertSame('current-run', $options['info']['OriginalRunID']);
        self::assertSame('parent-namespace', $options['info']['ParentWorkflowNamespace']);
        self::assertSame([
            'ID' => 'parent-id',
            'RunID' => 'parent-run',
        ], $options['info']['ParentWorkflowExecution']);
        self::assertSame([
            'ID' => 'root-id',
            'RunID' => 'root-run',
        ], $options['info']['RootWorkflowExecution']);
        self::assertSame(3.0, $options['info']['RetryPolicy']['backoff_coefficient']);
        self::assertSame(10, $options['info']['RetryPolicy']['maximum_attempts']);
        self::assertSame(2, $options['info']['Priority']['PriorityKey']);
        self::assertSame([
            'CustomKeywordField' => [
                'type' => 'Keyword',
                'value' => 'search-value',
            ],
        ], $options['search_attributes']);

        self::assertSame('argument', $commands[0]->getPayloads()->getValue(0));
        self::assertSame('previous-result', $commands[0]->getPayloads()->getValue(1));
        self::assertSame('trace-value', $commands[0]->getHeader()->getValue('trace-id'));
    }

    public function testChildStartResolvesWithTypedWorkflowExecution(): void
    {
        $converter = DataConverter::createDefault();
        $codec = new CoresdkWorkflowCodec($converter);

        \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('parent-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('ParentWorkflow')
                            ->setWorkflowId('parent-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        $execute = new ExecuteChildWorkflow(
            'ChildWorkflow',
            EncodedValues::empty(),
            ['Namespace' => 'default'],
            Header::empty(),
        );
        $getExecution = new GetChildWorkflowExecution($execute);
        $codec->stage($execute);
        $codec->stage($getExecution);

        $responses = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('parent-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setResolveChildWorkflowExecutionStart(
                        (new ResolveChildWorkflowExecutionStart())
                            ->setSeq(1)
                            ->setSucceeded(
                                (new ResolveChildWorkflowExecutionStartSuccess())
                                    ->setRunId('child-run'),
                            ),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        self::assertCount(1, $responses);
        self::assertInstanceOf(SuccessResponse::class, $responses[0]);
        self::assertSame($getExecution->getID(), $responses[0]->getID());

        $execution = $responses[0]->getPayloads()->getValue(0, SdkWorkflowExecution::class);
        self::assertInstanceOf(SdkWorkflowExecution::class, $execution);
        self::assertSame('parent-id_1', $execution->getID());
        self::assertSame('child-run', $execution->getRunID());
    }

    public function testChildStartCarriesSearchAttributes(): void
    {
        $converter = DataConverter::createDefault();
        $codec = new CoresdkWorkflowCodec($converter);

        \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('parent-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('ParentWorkflow')
                            ->setWorkflowId('parent-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        $codec->stage(new ExecuteChildWorkflow(
            'ChildWorkflow',
            EncodedValues::empty(),
            [
                'Namespace' => 'default',
                'SearchAttributes' => (object) [
                    'CustomKeywordField' => 'search-value',
                ],
            ],
            Header::empty(),
        ));

        $completion = new \Coresdk\WorkflowCompletion\WorkflowActivationCompletion();
        $completion->mergeFromString($codec->encodeStaged());

        $commands = $completion->getSuccessful()->getCommands();
        self::assertCount(1, $commands);

        $start = $commands[0]->getStartChildWorkflowExecution();
        self::assertTrue($start->hasSearchAttributes());
        self::assertSame(
            'search-value',
            $converter->fromPayload(
                $start->getSearchAttributes()->getIndexedFields()['CustomKeywordField'],
                null,
            ),
        );

        $codec->stage(new ExecuteChildWorkflow(
            'ChildWorkflow',
            EncodedValues::empty(),
            [
                'Namespace' => 'default',
                'SearchAttributes' => (object) [],
            ],
            Header::empty(),
        ));

        $completion = new \Coresdk\WorkflowCompletion\WorkflowActivationCompletion();
        $completion->mergeFromString($codec->encodeStaged());

        $empty = $completion->getSuccessful()->getCommands()[0]
            ->getStartChildWorkflowExecution();
        self::assertTrue($empty->hasSearchAttributes());
        self::assertCount(0, $empty->getSearchAttributes()->getIndexedFields());
    }

    public function testCommandsCarryUserMetadata(): void
    {
        $converter = DataConverter::createDefault();
        $codec = new CoresdkWorkflowCodec($converter);

        \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('parent-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('ParentWorkflow')
                            ->setWorkflowId('parent-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        $codec->stage(new Request('NewTimer', [
            'ms' => 1_000,
            'summary' => 'timer summary',
        ]));
        $codec->stage(new Request('ExecuteActivity', [
            'name' => 'Activity.run',
            'options' => ['Summary' => 'activity summary'],
        ]));
        $codec->stage(new Request('ExecuteLocalActivity', [
            'name' => 'LocalActivity.run',
            'options' => ['Summary' => 'local activity summary'],
        ]));
        $codec->stage(new Request('ExecuteChildWorkflow', [
            'name' => 'ChildWorkflow',
            'options' => [
                'Namespace' => 'default',
                'StaticSummary' => 'child summary',
                'StaticDetails' => 'child details',
            ],
        ]));
        $codec->stage(new Request(
            'SideEffect',
            ['summary' => 'side effect summary'],
            EncodedValues::fromValues([42], $converter),
        ));

        $completion = new \Coresdk\WorkflowCompletion\WorkflowActivationCompletion();
        $completion->mergeFromString($codec->encodeStaged());
        $commands = $completion->getSuccessful()->getCommands();

        self::assertCount(5, $commands);
        self::assertSame(
            'timer summary',
            $converter->fromPayload($commands[0]->getUserMetadata()->getSummary(), null),
        );
        self::assertSame(
            'activity summary',
            $converter->fromPayload($commands[1]->getUserMetadata()->getSummary(), null),
        );
        self::assertSame(
            'local activity summary',
            $converter->fromPayload($commands[2]->getUserMetadata()->getSummary(), null),
        );
        self::assertSame(
            'child summary',
            $converter->fromPayload($commands[3]->getUserMetadata()->getSummary(), null),
        );
        self::assertSame(
            'child details',
            $converter->fromPayload($commands[3]->getUserMetadata()->getDetails(), null),
        );
        self::assertSame(
            'side effect summary',
            $converter->fromPayload($commands[4]->getUserMetadata()->getSummary(), null),
        );
    }

    public function testNexusScheduleMapsFieldsTimeoutsHeadersSummaryAndCancellationTypes(): void
    {
        $converter = DataConverter::createDefault();
        $codec = $this->initializedCodec($converter);
        $cancellationTypes = [
            [
                NexusOperationCancellationType::Unspecified->value,
                CoresdkNexusCancellationType::WAIT_CANCELLATION_COMPLETED,
            ],
            [
                NexusOperationCancellationType::Abandon->value,
                CoresdkNexusCancellationType::ABANDON,
            ],
            [
                NexusOperationCancellationType::TryCancel->value,
                CoresdkNexusCancellationType::TRY_CANCEL,
            ],
            [
                NexusOperationCancellationType::WaitRequested->value,
                CoresdkNexusCancellationType::WAIT_CANCELLATION_REQUESTED,
            ],
            [
                NexusOperationCancellationType::WaitCompleted->value,
                CoresdkNexusCancellationType::WAIT_CANCELLATION_COMPLETED,
            ],
        ];

        foreach ($cancellationTypes as [$sdkType]) {
            $codec->stage($this->nexusRequest($converter, [
                'summary' => 'Capture the payment',
                'scheduleToCloseTimeout' => 1_500_000_000,
                'scheduleToStartTimeout' => 2_000_000_000,
                'startToCloseTimeout' => 3_250_000_000,
                'cancellationType' => $sdkType,
            ]));
        }

        $completion = self::completion($codec);
        $commands = $completion->getSuccessful()->getCommands();
        self::assertCount(5, $commands);

        foreach ($commands as $index => $command) {
            $schedule = $command->getScheduleNexusOperation();
            self::assertSame($index + 1, $schedule->getSeq());
            self::assertSame('payments', $schedule->getEndpoint());
            self::assertSame('PaymentsService', $schedule->getService());
            self::assertSame('capture', $schedule->getOperation());
            self::assertSame(
                $cancellationTypes[$index][1],
                $schedule->getCancellationType(),
            );
        }

        $schedule = $commands[0]->getScheduleNexusOperation();
        self::assertSame('payment-input', $converter->fromPayload($schedule->getInput(), null));
        $nexusHeaders = \iterator_to_array($schedule->getNexusHeader());
        \ksort($nexusHeaders);
        self::assertSame([
            'x-request-id' => 'request-123',
            'x-tenant' => 'tenant-a',
        ], $nexusHeaders);
        self::assertSame(1, (int) $schedule->getScheduleToCloseTimeout()->getSeconds());
        self::assertSame(500_000_000, $schedule->getScheduleToCloseTimeout()->getNanos());
        self::assertSame(2, (int) $schedule->getScheduleToStartTimeout()->getSeconds());
        self::assertSame(0, $schedule->getScheduleToStartTimeout()->getNanos());
        self::assertSame(3, (int) $schedule->getStartToCloseTimeout()->getSeconds());
        self::assertSame(250_000_000, $schedule->getStartToCloseTimeout()->getNanos());
        self::assertSame(
            'Capture the payment',
            $converter->fromPayload($commands[0]->getUserMetadata()->getSummary(), null),
        );
    }

    public function testNexusAsyncAndSyncStartsCorrelateWithTheirResultPromises(): void
    {
        $converter = DataConverter::createDefault();
        $codec = $this->initializedCodec($converter);

        $asyncExecute = $this->nexusRequest($converter);
        $asyncStarted = new GetNexusOperationStarted($asyncExecute->getID());
        $codec->stage($asyncExecute);
        $codec->stage($asyncStarted);
        self::completion($codec);

        $startResponses = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('nexus-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setResolveNexusOperationStart(
                        (new ResolveNexusOperationStart())
                            ->setSeq(1)
                            ->setOperationToken('operation-token'),
                    ),
                ])
                ->serializeToString(),
        ));

        self::assertCount(1, $startResponses);
        self::assertInstanceOf(SuccessResponse::class, $startResponses[0]);
        self::assertSame($asyncStarted->getID(), $startResponses[0]->getID());
        $envelope = $startResponses[0]->getPayloads()->getValue(0, NexusStartEnvelope::class);
        self::assertInstanceOf(NexusStartEnvelope::class, $envelope);
        self::assertTrue($envelope->async);
        self::assertSame('operation-token', $envelope->token);

        $resultResponses = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('nexus-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setResolveNexusOperation(
                        (new ResolveNexusOperation())
                            ->setSeq(1)
                            ->setResult(
                                (new NexusOperationResult())
                                    ->setCompleted($converter->toPayload('async-result')),
                            ),
                    ),
                ])
                ->serializeToString(),
        ));

        self::assertCount(1, $resultResponses);
        self::assertInstanceOf(SuccessResponse::class, $resultResponses[0]);
        self::assertSame($asyncExecute->getID(), $resultResponses[0]->getID());
        self::assertSame('async-result', $resultResponses[0]->getPayloads()->getValue(0, null));

        $syncExecute = $this->nexusRequest($converter);
        $syncStarted = new GetNexusOperationStarted($syncExecute->getID());
        $codec->stage($syncExecute);
        $codec->stage($syncStarted);
        self::completion($codec);

        $syncResponses = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('nexus-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setResolveNexusOperationStart(
                        (new ResolveNexusOperationStart())
                            ->setSeq(2)
                            ->setStartedSync(true),
                    ),
                    (new WorkflowActivationJob())->setResolveNexusOperation(
                        (new ResolveNexusOperation())
                            ->setSeq(2)
                            ->setResult(
                                (new NexusOperationResult())
                                    ->setCompleted($converter->toPayload('sync-result')),
                            ),
                    ),
                ])
                ->serializeToString(),
        ), false);

        self::assertCount(2, $syncResponses);
        self::assertInstanceOf(SuccessResponse::class, $syncResponses[0]);
        self::assertSame($syncStarted->getID(), $syncResponses[0]->getID());
        $envelope = $syncResponses[0]->getPayloads()->getValue(0, NexusStartEnvelope::class);
        self::assertFalse($envelope->async);
        self::assertSame('', $envelope->token);
        self::assertInstanceOf(SuccessResponse::class, $syncResponses[1]);
        self::assertSame($syncExecute->getID(), $syncResponses[1]->getID());
        self::assertSame('sync-result', $syncResponses[1]->getPayloads()->getValue(0, null));
    }

    public function testNexusFailedStartRejectsBothStartAndResultPromises(): void
    {
        $converter = DataConverter::createDefault();
        $codec = $this->initializedCodec($converter);
        $execute = $this->nexusRequest($converter);
        $started = new GetNexusOperationStarted($execute->getID());
        $codec->stage($execute);
        $codec->stage($started);
        self::completion($codec);

        $responses = \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId('nexus-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setResolveNexusOperationStart(
                        (new ResolveNexusOperationStart())
                            ->setSeq(1)
                            ->setFailed(self::failure('Nexus start failed')),
                    ),
                ])
                ->serializeToString(),
        ));

        self::assertCount(2, $responses);
        self::assertInstanceOf(FailureResponse::class, $responses[0]);
        self::assertSame($started->getID(), $responses[0]->getID());
        self::assertStringContainsString(
            'Nexus start failed',
            $responses[0]->getFailure()->getMessage(),
        );
        self::assertInstanceOf(FailureResponse::class, $responses[1]);
        self::assertSame($execute->getID(), $responses[1]->getID());
        self::assertStringContainsString(
            'Nexus start failed',
            $responses[1]->getFailure()->getMessage(),
        );
    }

    public function testNexusFailedCancelledAndTimedOutResultsRejectExecutePromise(): void
    {
        $statuses = ['failed', 'cancelled', 'timed_out'];

        foreach ($statuses as $status) {
            $converter = DataConverter::createDefault();
            $codec = $this->initializedCodec($converter, "nexus-{$status}");
            $execute = $this->nexusRequest($converter);
            $started = new GetNexusOperationStarted($execute->getID());
            $codec->stage($execute);
            $codec->stage($started);
            self::completion($codec);

            \iterator_to_array($codec->decode(
                (new WorkflowActivation())
                    ->setRunId("nexus-{$status}")
                    ->setJobs([
                        (new WorkflowActivationJob())->setResolveNexusOperationStart(
                            (new ResolveNexusOperationStart())
                                ->setSeq(1)
                                ->setOperationToken("token-{$status}"),
                        ),
                    ])
                    ->serializeToString(),
            ));

            $failure = self::failure("Nexus {$status}");
            $result = match ($status) {
                'failed' => (new NexusOperationResult())->setFailed($failure),
                'cancelled' => (new NexusOperationResult())->setCancelled($failure),
                'timed_out' => (new NexusOperationResult())->setTimedOut($failure),
            };
            $responses = \iterator_to_array($codec->decode(
                (new WorkflowActivation())
                    ->setRunId("nexus-{$status}")
                    ->setJobs([
                        (new WorkflowActivationJob())->setResolveNexusOperation(
                            (new ResolveNexusOperation())
                                ->setSeq(1)
                                ->setResult($result),
                        ),
                    ])
                    ->serializeToString(),
            ));

            self::assertCount(1, $responses);
            self::assertInstanceOf(FailureResponse::class, $responses[0]);
            self::assertSame($execute->getID(), $responses[0]->getID());
            self::assertStringContainsString(
                "Nexus {$status}",
                $responses[0]->getFailure()->getMessage(),
            );
        }
    }

    public function testNexusCancellationUsesScheduleSeqAndSeqIsReplayStable(): void
    {
        $converter = DataConverter::createDefault();
        $live = $this->initializedCodec($converter, 'live-run');
        $liveExecute = $this->nexusRequest($converter);
        $live->stage($liveExecute);
        self::assertSame([], $live->stage(new Request('Cancel', [
            'ids' => [$liveExecute->getID()],
        ])));

        $liveCommands = self::completion($live)->getSuccessful()->getCommands();
        self::assertCount(2, $liveCommands);
        self::assertSame(1, $liveCommands[0]->getScheduleNexusOperation()->getSeq());
        self::assertSame(1, $liveCommands[1]->getRequestCancelNexusOperation()->getSeq());

        $replay = $this->initializedCodec($converter, 'replay-run', true);
        $replayExecute = $this->nexusRequest($converter);
        self::assertNotSame($liveExecute->getID(), $replayExecute->getID());
        $replay->stage($replayExecute);

        $replayCommands = self::completion($replay)->getSuccessful()->getCommands();
        self::assertCount(1, $replayCommands);
        self::assertSame(1, $replayCommands[0]->getScheduleNexusOperation()->getSeq());
    }

    public function testGetVersionPersistsTheSelectedIntegerInPatchId(): void
    {
        $converter = DataConverter::createDefault();
        $live = new CoresdkWorkflowCodec($converter);
        \iterator_to_array($live->decode(
            (new WorkflowActivation())
                ->setRunId('live-run')
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('VersionedWorkflow')
                            ->setWorkflowId('workflow-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        $live->stage(new Request('GetVersion', [
            'changeID' => 'feature/name',
            'minSupported' => -1,
            'maxSupported' => 2,
        ]));
        self::assertSame(2, $live->drainVersionResolutions()[0]['version']);

        $completion = new \Coresdk\WorkflowCompletion\WorkflowActivationCompletion();
        $completion->mergeFromString($live->encodeStaged());
        $patchId = $completion->getSuccessful()->getCommands()[0]->getSetPatchMarker()->getPatchId();
        self::assertSame('__temporal_php_get_version:feature%2Fname:2', $patchId);

        $replay = new CoresdkWorkflowCodec($converter);
        \iterator_to_array($replay->decode(
            (new WorkflowActivation())
                ->setRunId('replay-run')
                ->setIsReplaying(true)
                ->setJobs([
                    (new WorkflowActivationJob())->setNotifyHasPatch(
                        (new NotifyHasPatch())->setPatchId($patchId),
                    ),
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('VersionedWorkflow')
                            ->setWorkflowId('workflow-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        $replay->stage(new Request('GetVersion', [
            'changeID' => 'feature/name',
            'minSupported' => -1,
            'maxSupported' => 3,
        ]));
        self::assertSame(2, $replay->drainVersionResolutions()[0]['version']);

        $completion->clear();
        $completion->mergeFromString($replay->encodeStaged());
        self::assertSame(
            $patchId,
            $completion->getSuccessful()->getCommands()[0]->getSetPatchMarker()->getPatchId(),
        );
    }

    private static function completion(CoresdkWorkflowCodec $codec): WorkflowActivationCompletion
    {
        $completion = new WorkflowActivationCompletion();
        $completion->mergeFromString($codec->encodeStaged());

        return $completion;
    }

    private static function failure(string $message): Failure
    {
        return (new Failure())
            ->setMessage($message)
            ->setApplicationFailureInfo(
                (new ApplicationFailureInfo())
                    ->setType('NexusTestFailure')
                    ->setNonRetryable(true),
            );
    }

    private function initializedCodec(
        DataConverterInterface $converter,
        string $runId = 'nexus-run',
        bool $isReplaying = false,
    ): CoresdkWorkflowCodec {
        $codec = new CoresdkWorkflowCodec($converter);
        \iterator_to_array($codec->decode(
            (new WorkflowActivation())
                ->setRunId($runId)
                ->setIsReplaying($isReplaying)
                ->setJobs([
                    (new WorkflowActivationJob())->setInitializeWorkflow(
                        (new InitializeWorkflow())
                            ->setWorkflowType('NexusWorkflow')
                            ->setWorkflowId('workflow-id'),
                    ),
                ])
                ->serializeToString(),
            ['taskQueue' => 'queue', 'namespace' => 'default'],
        ));

        return $codec;
    }

    /**
     * @param array<non-empty-string, mixed> $options
     */
    private function nexusRequest(
        DataConverterInterface $converter,
        array $options = [],
    ): ExecuteNexusOperation {
        return new ExecuteNexusOperation(
            endpoint: 'payments',
            service: 'PaymentsService',
            operation: 'capture',
            args: EncodedValues::fromValues(['payment-input'], $converter),
            options: $options,
            header: Header::empty()->withValue('interceptor-trace', 'internal-only'),
            nexusHeaders: [
                'x-request-id' => 'request-123',
                'x-tenant' => 'tenant-a',
            ],
        );
    }
}
