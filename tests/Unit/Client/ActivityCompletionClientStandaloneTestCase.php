<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatByIdRequest;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatByIdResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledByIdRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledByIdResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedByIdRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedByIdResponse;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\Internal\Client\ActivityCompletionClient;

final class ActivityCompletionClientStandaloneTestCase extends TestCase
{
    public function testCompleteAddressesStandaloneActivityByEmptyWorkflowAndActivityRunId(): void
    {
        $service = $this->createMock(ServiceClientInterface::class);
        $service
            ->expects(self::once())
            ->method('RespondActivityTaskCompletedById')
            ->with(self::callback(static fn(RespondActivityTaskCompletedByIdRequest $request): bool =>
                $request->getNamespace() === 'test-namespace'
                && $request->getWorkflowId() === ''
                && $request->getRunId() === 'activity-run-id'
                && $request->getActivityId() === 'activity-id'
                && $request->hasResult()))
            ->willReturn(new RespondActivityTaskCompletedByIdResponse());

        $this->client($service)->complete(
            '',
            'activity-run-id',
            'activity-id',
            'standalone-result',
        );
    }

    public function testHeartbeatAndCancellationUseTheSameStandaloneAddress(): void
    {
        $service = $this->createMock(ServiceClientInterface::class);
        $matchesStandaloneAddress = static fn(
            RecordActivityTaskHeartbeatByIdRequest|RespondActivityTaskCanceledByIdRequest $request,
        ): bool =>
            $request->getWorkflowId() === ''
            && $request->getRunId() === 'activity-run-id'
            && $request->getActivityId() === 'activity-id';

        $service
            ->expects(self::once())
            ->method('RecordActivityTaskHeartbeatById')
            ->with(self::callback($matchesStandaloneAddress))
            ->willReturn(new RecordActivityTaskHeartbeatByIdResponse());
        $service
            ->expects(self::once())
            ->method('RespondActivityTaskCanceledById')
            ->with(self::callback($matchesStandaloneAddress))
            ->willReturn(new RespondActivityTaskCanceledByIdResponse());

        $client = $this->client($service);
        $client->recordHeartbeat('', 'activity-run-id', 'activity-id', 'progress');
        $client->reportCancellation('', 'activity-run-id', 'activity-id', 'canceled');
    }

    private function client(ServiceClientInterface $service): ActivityCompletionClient
    {
        return new ActivityCompletionClient(
            $service,
            (new ClientOptions())
                ->withNamespace('test-namespace')
                ->withIdentity('test-identity'),
            DataConverter::createDefault(),
        );
    }
}
