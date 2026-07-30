<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

use Temporal\Api\Activity\V1\ActivityExecutionInfo as ProtoActivityExecutionInfo;
use Temporal\Api\Workflowservice\V1\DescribeActivityExecutionResponse;
use Temporal\DataConverter\ActivitySerializationContext;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\SerializationContextBinder;
use Temporal\Exception\Failure\FailureConverter;
use Temporal\Exception\Failure\TemporalFailure;

/**
 * Detailed state returned by describing a standalone Activity.
 *
 * @experimental Standalone Activities are a Temporal Server Public Preview feature.
 */
final class ActivityExecutionDescription
{
    public readonly string $activityId;
    public readonly string $runId;
    public readonly string $activityType;
    public readonly int $status;
    public readonly int $runState;
    public readonly string $taskQueue;
    public readonly int $attempt;
    public readonly string $canceledReason;

    public function __construct(
        public readonly DescribeActivityExecutionResponse $raw,
        private readonly DataConverterInterface $converter,
        private readonly ActivitySerializationContext $serializationContext,
    ) {
        $info = $raw->getInfo() ?? new ProtoActivityExecutionInfo();
        $this->activityId = $info->getActivityId();
        $this->runId = $raw->getRunId() !== '' ? $raw->getRunId() : $info->getRunId();
        $this->activityType = $info->getActivityType()?->getName() ?? '';
        $this->status = $info->getStatus();
        $this->runState = $info->getRunState();
        $this->taskQueue = $info->getTaskQueue();
        $this->attempt = $info->getAttempt();
        $this->canceledReason = $info->getCanceledReason();
    }

    public function getInput(mixed $type = null): mixed
    {
        $values = EncodedValues::fromPayloads(
            $this->raw->getInput() ?? new \Temporal\Api\Common\V1\Payloads(),
            $this->converter,
        )->withSerializationContext($this->serializationContext);

        return $values->count() === 0 ? null : $values->getValue(0, $type);
    }

    public function hasOutcome(): bool
    {
        return $this->raw->hasOutcome();
    }

    /**
     * Decode the successful terminal result returned when `includeOutcome`
     * was requested. Returns null when the execution is still open, failed,
     * or completed without a result payload.
     */
    public function getResult(mixed $type = null): mixed
    {
        $outcome = $this->raw->getOutcome();
        if ($outcome === null || !$outcome->hasResult()) {
            return null;
        }

        $values = EncodedValues::fromPayloads(
            $outcome->getResult() ?? new \Temporal\Api\Common\V1\Payloads(),
            $this->converter,
        )->withSerializationContext($this->serializationContext);

        return $values->count() === 0 ? null : $values->getValue(0, $type);
    }

    /**
     * Decode the terminal failure returned when `includeOutcome` was
     * requested. This differs from `getLastFailure()`, which is the most
     * recent failed attempt and can be present while the Activity is retrying.
     */
    public function getFailure(): ?TemporalFailure
    {
        $failure = $this->raw->getOutcome()?->getFailure();

        return $failure === null
            ? null
            : FailureConverter::mapFailureToException($failure, $this->converter)
                ->withSerializationContext($this->serializationContext);
    }

    public function hasHeartbeatDetails(): bool
    {
        return $this->raw->getInfo()?->hasHeartbeatDetails() ?? false;
    }

    public function getHeartbeatDetails(): EncodedValues
    {
        return EncodedValues::fromPayloads(
            $this->raw->getInfo()?->getHeartbeatDetails() ?? new \Temporal\Api\Common\V1\Payloads(),
            $this->converter,
        )->withSerializationContext($this->serializationContext);
    }

    public function getLastFailure(): ?TemporalFailure
    {
        $failure = $this->raw->getInfo()?->getLastFailure();
        return $failure === null
            ? null
            : FailureConverter::mapFailureToException($failure, $this->converter)
                ->withSerializationContext($this->serializationContext);
    }

    public function getSummary(): ?string
    {
        $payload = $this->raw->getInfo()?->getUserMetadata()?->getSummary();
        return $payload === null
            ? null
            : SerializationContextBinder::bind($this->converter, $this->serializationContext)
                ->fromPayload($payload, 'string');
    }

    public function getDetails(): ?string
    {
        $payload = $this->raw->getInfo()?->getUserMetadata()?->getDetails();
        return $payload === null
            ? null
            : SerializationContextBinder::bind($this->converter, $this->serializationContext)
                ->fromPayload($payload, 'string');
    }
}
