<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Client\Activity;

use PHPUnit\Framework\TestCase;
use Temporal\Api\Activity\V1\ActivityExecutionListInfo;
use Temporal\Api\Activity\V1\ActivityExecutionOutcome;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\ListActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\PollActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionResponse;
use Temporal\Client\Activity\ActivityClient;
use Temporal\Client\Activity\ActivityOptions;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Common\ActivityIdConflictPolicy;
use Temporal\Common\ActivityIdReusePolicy;
use Temporal\Common\Priority;
use Temporal\Common\RetryOptions;
use Temporal\DataConverter\DataConverter;
use Temporal\Exception\Client\ActivityExecutionFailedException;
use Temporal\Exception\Failure\ApplicationFailure;

final class ActivityClientTestCase extends TestCase
{
    public function testStartMapsFullRequestAndReturnsHandle(): void
    {
        $captured = null;
        $service = $this->service();
        $service
            ->expects(self::once())
            ->method('StartActivityExecution')
            ->with(self::callback(static function (StartActivityExecutionRequest $request) use (&$captured): bool {
                $captured = $request;
                return true;
            }))
            ->willReturn((new StartActivityExecutionResponse())->setRunId('activity-run'));

        $options = ActivityOptions::new('activity-id', 'activity-queue')
            ->withScheduleToCloseTimeout(60)
            ->withScheduleToStartTimeout(5)
            ->withStartToCloseTimeout(30)
            ->withHeartbeatTimeout(10)
            ->withStartDelay(2)
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(3))
            ->withIdReusePolicy(ActivityIdReusePolicy::RejectDuplicate)
            ->withIdConflictPolicy(ActivityIdConflictPolicy::UseExisting)
            ->withHeaders(['trace-id' => 'abc'])
            ->withSearchAttributes(['CustomerId' => 'acme'])
            ->withSummary('Summary')
            ->withDetails('Details')
            ->withPriority(Priority::new(1)->withFairnessKey('acme'));

        $handle = $this->client($service)->start('Demo.activity', $options, 'input', 42);

        self::assertSame('activity-id', $handle->getId());
        self::assertSame('activity-run', $handle->getRunId());
        self::assertInstanceOf(StartActivityExecutionRequest::class, $captured);
        self::assertSame('test-namespace', $captured->getNamespace());
        self::assertSame('test-identity', $captured->getIdentity());
        self::assertSame('activity-id', $captured->getActivityId());
        self::assertSame('Demo.activity', $captured->getActivityType()?->getName());
        self::assertSame('activity-queue', $captured->getTaskQueue()?->getName());
        self::assertSame(60, $captured->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(5, $captured->getScheduleToStartTimeout()?->getSeconds());
        self::assertSame(30, $captured->getStartToCloseTimeout()?->getSeconds());
        self::assertSame(10, $captured->getHeartbeatTimeout()?->getSeconds());
        self::assertSame(2, $captured->getStartDelay()?->getSeconds());
        self::assertSame(3, $captured->getRetryPolicy()?->getMaximumAttempts());
        self::assertSame(ActivityIdReusePolicy::RejectDuplicate->value, $captured->getIdReusePolicy());
        self::assertSame(ActivityIdConflictPolicy::UseExisting->value, $captured->getIdConflictPolicy());
        self::assertSame(1, $captured->getPriority()?->getPriorityKey());
        self::assertSame('acme', $captured->getPriority()?->getFairnessKey());
        self::assertCount(2, $captured->getInput()?->getPayloads() ?? []);
        self::assertArrayHasKey('trace-id', \iterator_to_array($captured->getHeader()?->getFields() ?? []));
        self::assertArrayHasKey(
            'CustomerId',
            \iterator_to_array($captured->getSearchAttributes()?->getIndexedFields() ?? []),
        );
        self::assertNotNull($captured->getUserMetadata()?->getSummary());
        self::assertNotNull($captured->getUserMetadata()?->getDetails());
    }

    public function testStartRequiresTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client($this->service())->start(
            'Demo.activity',
            ActivityOptions::new('activity-id', 'activity-queue'),
        );
    }

    public function testResultMapsSuccessAndFailure(): void
    {
        $converter = DataConverter::createDefault();
        $service = $this->service();
        $service
            ->expects(self::exactly(2))
            ->method('PollActivityExecution')
            ->willReturnOnConsecutiveCalls(
                (new PollActivityExecutionResponse())
                    ->setRunId('activity-run')
                    ->setOutcome(
                        (new ActivityExecutionOutcome())->setResult(
                            (new Payloads())->setPayloads([$converter->toPayload('done')]),
                        ),
                    ),
                (new PollActivityExecutionResponse())
                    ->setRunId('activity-run')
                    ->setOutcome(
                        (new ActivityExecutionOutcome())->setFailure(
                            (new Failure())
                                ->setMessage('broken')
                                ->setApplicationFailureInfo(
                                    (new ApplicationFailureInfo())
                                        ->setType('DemoFailure')
                                        ->setNonRetryable(true),
                                ),
                        ),
                    ),
            );

        $handle = $this->client($service, $converter)->getHandle('activity-id', 'activity-run');
        self::assertSame('done', $handle->getResult('string'));

        try {
            $handle->getResult();
            self::fail('Expected failure.');
        } catch (ActivityExecutionFailedException $e) {
            self::assertInstanceOf(ApplicationFailure::class, $e->failure);
            self::assertSame('broken', $e->failure->getOriginalMessage());
        }
    }

    public function testResultRepollsUntilOutcomeWithFreshContextsAndResolvedRunId(): void
    {
        $converter = DataConverter::createDefault();
        $contexts = [];
        $requests = [];
        $service = $this->service();
        $service
            ->expects(self::exactly(2))
            ->method('PollActivityExecution')
            ->willReturnCallback(static function (
                \Temporal\Api\Workflowservice\V1\PollActivityExecutionRequest $request,
                ContextInterface $context,
            ) use (&$contexts, &$requests, $converter): PollActivityExecutionResponse {
                $contexts[] = $context;
                $requests[] = clone $request;

                if (\count($requests) === 1) {
                    return (new PollActivityExecutionResponse())->setRunId('resolved-run');
                }

                return (new PollActivityExecutionResponse())
                    ->setRunId('resolved-run')
                    ->setOutcome(
                        (new ActivityExecutionOutcome())->setResult(
                            (new Payloads())->setPayloads([$converter->toPayload('done')]),
                        ),
                    );
            });

        $handle = $this->client($service, $converter)->getHandle('activity-id');

        self::assertSame('done', $handle->getResult('string'));
        self::assertCount(2, $contexts);
        self::assertNotSame($contexts[0], $contexts[1]);
        self::assertSame('', $requests[0]->getRunId());
        self::assertSame('resolved-run', $requests[1]->getRunId());
        self::assertNotNull($contexts[0]->getDeadline());
        self::assertNotNull($contexts[1]->getDeadline());
    }

    public function testLifecycleMethodsCarryIdentityRunAndReasons(): void
    {
        $service = $this->service();
        $service
            ->expects(self::once())
            ->method('RequestCancelActivityExecution')
            ->with(self::callback(static fn(RequestCancelActivityExecutionRequest $request): bool =>
                $request->getActivityId() === 'activity-id'
                && $request->getRunId() === 'activity-run'
                && $request->getIdentity() === 'test-identity'
                && $request->getReason() === 'cancel reason'))
            ->willReturn(new RequestCancelActivityExecutionResponse());
        $service
            ->expects(self::once())
            ->method('TerminateActivityExecution')
            ->with(self::callback(static fn(TerminateActivityExecutionRequest $request): bool =>
                $request->getActivityId() === 'activity-id'
                && $request->getRunId() === 'activity-run'
                && $request->getReason() === 'terminate reason'))
            ->willReturn(new TerminateActivityExecutionResponse());
        $service
            ->expects(self::once())
            ->method('DeleteActivityExecution')
            ->with(self::callback(static fn(DeleteActivityExecutionRequest $request): bool =>
                $request->getActivityId() === 'activity-id'
                && $request->getRunId() === 'activity-run'))
            ->willReturn(new DeleteActivityExecutionResponse());

        $handle = $this->client($service)->getHandle('activity-id', 'activity-run');
        $handle->cancel('cancel reason');
        $handle->terminate('terminate reason');
        $handle->delete();
    }

    public function testListAndCount(): void
    {
        $service = $this->service();
        $service
            ->expects(self::once())
            ->method('ListActivityExecutions')
            ->willReturn(
                (new ListActivityExecutionsResponse())->setExecutions([
                    (new ActivityExecutionListInfo())
                        ->setActivityId('activity-id')
                        ->setRunId('activity-run')
                        ->setActivityType((new ActivityType())->setName('Demo.activity'))
                        ->setTaskQueue('activity-queue'),
                ]),
            );
        $service
            ->expects(self::once())
            ->method('CountActivityExecutions')
            ->willReturn((new CountActivityExecutionsResponse())->setCount(1));

        $client = $this->client($service);
        $items = \iterator_to_array($client->list('ActivityType = "Demo.activity"'));

        self::assertCount(1, $items);
        self::assertSame('activity-id', $items[0]->activityId);
        self::assertSame('activity-run', $items[0]->runId);
        self::assertSame(1, $client->count()->count);
    }

    private function client(
        ServiceClientInterface $service,
        ?\Temporal\DataConverter\DataConverterInterface $converter = null,
    ): ActivityClient {
        return new ActivityClient(
            $service,
            (new ClientOptions())
                ->withNamespace('test-namespace')
                ->withIdentity('test-identity'),
            $converter ?? DataConverter::createDefault(),
        );
    }

    private function service(): ServiceClientInterface
    {
        $service = $this->createMock(ServiceClientInterface::class);
        $service->method('withContext')->willReturnSelf();
        $service->method('getContext')->willReturn(Context::default());
        return $service;
    }
}
