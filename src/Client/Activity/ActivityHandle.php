<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\PollActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionRequest;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Common\Uuid;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Client\ActivityExecutionFailedException;
use Temporal\Exception\Client\ActivityExecutionNotFoundException;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\TimeoutException;
use Temporal\Exception\Failure\FailureConverter;

/**
 * @internal Construct through {@see ActivityClientInterface}.
 */
final class ActivityHandle implements ActivityHandleInterface
{
    private const POLL_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly ServiceClientInterface $client,
        private readonly ClientOptions $clientOptions,
        private readonly DataConverterInterface $converter,
        private readonly string $namespace,
        private readonly string $activityId,
        private readonly ?string $runId,
    ) {}

    public function getId(): string
    {
        return $this->activityId;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function getResult(mixed $type = null): mixed
    {
        $request = (new PollActivityExecutionRequest())
            ->setNamespace($this->namespace)
            ->setActivityId($this->activityId)
            ->setRunId($this->runId ?? '');
        $baseContext = $this->client->getContext();
        $deadline = $baseContext->getDeadline();
        $runId = $this->runId ?? '';
        $outcome = null;

        do {
            $response = null;
            try {
                $response = $this->client->PollActivityExecution(
                    $request,
                    $this->pollContext($baseContext, $deadline),
                );
            } catch (TimeoutException $e) {
                if ($deadline !== null && new \DateTimeImmutable() >= $deadline) {
                    throw $e;
                }

                // The server-side long poll is bounded. Retry with a fresh
                // per-call context while the caller's overall deadline remains.
                continue;
            } catch (ServiceClientException $e) {
                throw $this->mapServiceException($e);
            }

            if ($response === null) {
                continue;
            }
            if ($response->getRunId() !== '') {
                $runId = $response->getRunId();
                $request->setRunId($runId);
            }
            $outcome = $response->getOutcome();
        } while ($outcome === null);

        if ($runId === '') {
            $runId = $this->runId ?? '';
        }

        if ($outcome->hasFailure()) {
            $failure = FailureConverter::mapFailureToException($outcome->getFailure(), $this->converter);
            throw new ActivityExecutionFailedException($this->activityId, $runId, $failure);
        }

        $values = EncodedValues::fromPayloads(
            $outcome->getResult() ?? new \Temporal\Api\Common\V1\Payloads(),
            $this->converter,
        );

        return $values->count() === 0 ? null : $values->getValue(0, $type);
    }

    public function describe(
        bool $includeInput = true,
        bool $includeOutcome = true,
    ): ActivityExecutionDescription {
        try {
            $response = $this->client->DescribeActivityExecution(
                (new DescribeActivityExecutionRequest())
                    ->setNamespace($this->namespace)
                    ->setActivityId($this->activityId)
                    ->setRunId($this->runId ?? '')
                    ->setIncludeInput($includeInput)
                    ->setIncludeOutcome($includeOutcome),
            );
        } catch (ServiceClientException $e) {
            throw $this->mapServiceException($e);
        }

        return new ActivityExecutionDescription($response, $this->converter);
    }

    public function cancel(string $reason = ''): void
    {
        try {
            $this->client->RequestCancelActivityExecution(
                (new RequestCancelActivityExecutionRequest())
                    ->setNamespace($this->namespace)
                    ->setIdentity($this->clientOptions->identity)
                    ->setRequestId(Uuid::v4())
                    ->setActivityId($this->activityId)
                    ->setRunId($this->runId ?? '')
                    ->setReason($reason),
            );
        } catch (ServiceClientException $e) {
            throw $this->mapServiceException($e);
        }
    }

    public function terminate(string $reason = ''): void
    {
        try {
            $this->client->TerminateActivityExecution(
                (new TerminateActivityExecutionRequest())
                    ->setNamespace($this->namespace)
                    ->setIdentity($this->clientOptions->identity)
                    ->setRequestId(Uuid::v4())
                    ->setActivityId($this->activityId)
                    ->setRunId($this->runId ?? '')
                    ->setReason($reason),
            );
        } catch (ServiceClientException $e) {
            throw $this->mapServiceException($e);
        }
    }

    public function delete(): void
    {
        try {
            $this->client->DeleteActivityExecution(
                (new DeleteActivityExecutionRequest())
                    ->setNamespace($this->namespace)
                    ->setActivityId($this->activityId)
                    ->setRunId($this->runId ?? ''),
            );
        } catch (ServiceClientException $e) {
            throw $this->mapServiceException($e);
        }
    }

    private function mapServiceException(ServiceClientException $e): \Throwable
    {
        return $e->getCode() === StatusCode::NOT_FOUND
            ? new ActivityExecutionNotFoundException($this->activityId, $this->runId, $e)
            : $e;
    }

    private function pollContext(
        ContextInterface $base,
        ?\DateTimeInterface $deadline,
    ): ContextInterface {
        if ($deadline === null) {
            return $base->withTimeout(self::POLL_TIMEOUT_SECONDS);
        }

        $pollDeadline = (new \DateTimeImmutable())->modify('+' . self::POLL_TIMEOUT_SECONDS . ' seconds');

        return $base->withDeadline($deadline < $pollDeadline ? $deadline : $pollDeadline);
    }
}
