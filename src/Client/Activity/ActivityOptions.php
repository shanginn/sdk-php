<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

use Carbon\CarbonInterval;
use JetBrains\PhpStorm\Pure;
use Temporal\Api\Common\V1\SearchAttributes;
use Temporal\Common\ActivityIdConflictPolicy;
use Temporal\Common\ActivityIdReusePolicy;
use Temporal\Common\Priority;
use Temporal\Common\RetryOptions;
use Temporal\Common\TypedSearchAttributes;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\HeaderInterface;
use Temporal\Internal\Support\DateInterval;

/**
 * Options for starting a standalone Activity directly from a client.
 *
 * Activity ID and task queue are required. At least one of schedule-to-close
 * or start-to-close timeout must be non-zero.
 *
 * @psalm-import-type DateIntervalValue from DateInterval
 * @experimental Requires Temporal Server 1.31+ with Standalone Activities enabled.
 */
final class ActivityOptions
{
    public \DateInterval $scheduleToCloseTimeout;
    public \DateInterval $scheduleToStartTimeout;
    public \DateInterval $startToCloseTimeout;
    public \DateInterval $heartbeatTimeout;
    public \DateInterval $startDelay;
    public ?RetryOptions $retryOptions = null;
    public ActivityIdReusePolicy $idReusePolicy = ActivityIdReusePolicy::AllowDuplicate;
    public ActivityIdConflictPolicy $idConflictPolicy = ActivityIdConflictPolicy::Fail;
    public ?array $searchAttributes = null;
    public ?TypedSearchAttributes $typedSearchAttributes = null;
    public Header $header;
    public string $summary = '';
    public string $details = '';
    public Priority $priority;
    public ?string $requestId = null;

    private function __construct(
        public readonly string $activityId,
        public readonly string $taskQueue,
    ) {
        if ($activityId === '') {
            throw new \InvalidArgumentException('Standalone Activity ID must not be empty.');
        }
        if ($taskQueue === '') {
            throw new \InvalidArgumentException('Standalone Activity task queue must not be empty.');
        }

        $this->scheduleToCloseTimeout = CarbonInterval::seconds(0);
        $this->scheduleToStartTimeout = CarbonInterval::seconds(0);
        $this->startToCloseTimeout = CarbonInterval::seconds(0);
        $this->heartbeatTimeout = CarbonInterval::seconds(0);
        $this->startDelay = CarbonInterval::seconds(0);
        $this->header = Header::empty();
        $this->priority = Priority::new();
    }

    public static function new(string $activityId, string $taskQueue): self
    {
        return new self($activityId, $taskQueue);
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withScheduleToCloseTimeout(mixed $timeout): self
    {
        return $this->withDuration('scheduleToCloseTimeout', $timeout);
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withScheduleToStartTimeout(mixed $timeout): self
    {
        return $this->withDuration('scheduleToStartTimeout', $timeout);
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withStartToCloseTimeout(mixed $timeout): self
    {
        return $this->withDuration('startToCloseTimeout', $timeout);
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withHeartbeatTimeout(mixed $timeout): self
    {
        return $this->withDuration('heartbeatTimeout', $timeout);
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $delay
     */
    #[Pure]
    public function withStartDelay(mixed $delay): self
    {
        return $this->withDuration('startDelay', $delay);
    }

    #[Pure]
    public function withRetryOptions(?RetryOptions $retryOptions): self
    {
        $clone = clone $this;
        $clone->retryOptions = $retryOptions;
        return $clone;
    }

    #[Pure]
    public function withIdReusePolicy(ActivityIdReusePolicy $policy): self
    {
        $clone = clone $this;
        $clone->idReusePolicy = $policy;
        return $clone;
    }

    #[Pure]
    public function withIdConflictPolicy(ActivityIdConflictPolicy $policy): self
    {
        $clone = clone $this;
        $clone->idConflictPolicy = $policy;
        return $clone;
    }

    /**
     * @param array<non-empty-string, mixed>|null $searchAttributes
     */
    #[Pure]
    public function withSearchAttributes(?array $searchAttributes): self
    {
        if ($this->typedSearchAttributes !== null) {
            throw new \LogicException('Cannot have typed and untyped search attributes at the same time.');
        }

        $clone = clone $this;
        $clone->searchAttributes = $searchAttributes;
        return $clone;
    }

    #[Pure]
    public function withTypedSearchAttributes(TypedSearchAttributes $searchAttributes): self
    {
        if ($this->searchAttributes !== null) {
            throw new \LogicException('Cannot have typed and untyped search attributes at the same time.');
        }

        $clone = clone $this;
        $clone->typedSearchAttributes = $searchAttributes;
        return $clone;
    }

    /**
     * @psalm-suppress ImpureMethodCall
     */
    #[Pure]
    public function withHeader(HeaderInterface $header): self
    {
        $clone = clone $this;
        $values = [];
        foreach ($header as $key => $value) {
            $values[$key] = $value;
        }
        $clone->header = Header::fromValues($values);
        return $clone;
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param iterable<array-key, mixed> $headers
     */
    #[Pure]
    public function withHeaders(iterable $headers): self
    {
        $clone = clone $this;
        $clone->header = Header::fromValues($headers);
        return $clone;
    }

    #[Pure]
    public function withSummary(string $summary): self
    {
        $clone = clone $this;
        $clone->summary = $summary;
        return $clone;
    }

    #[Pure]
    public function withDetails(string $details): self
    {
        $clone = clone $this;
        $clone->details = $details;
        return $clone;
    }

    #[Pure]
    public function withPriority(Priority $priority): self
    {
        $clone = clone $this;
        $clone->priority = $priority;
        return $clone;
    }

    #[Pure]
    public function withRequestId(?string $requestId): self
    {
        if ($requestId === '') {
            throw new \InvalidArgumentException('Request ID must be null or non-empty.');
        }

        $clone = clone $this;
        $clone->requestId = $requestId;
        return $clone;
    }

    /**
     * @internal
     */
    public function validate(): void
    {
        if (
            DateInterval::toDuration($this->scheduleToCloseTimeout, true) === null
            && DateInterval::toDuration($this->startToCloseTimeout, true) === null
        ) {
            throw new \InvalidArgumentException(
                'Standalone Activity requires schedule-to-close or start-to-close timeout.',
            );
        }
    }

    /**
     * @internal
     */
    public function toSearchAttributes(DataConverterInterface $converter): ?SearchAttributes
    {
        if ($this->searchAttributes === null && $this->typedSearchAttributes === null) {
            return null;
        }

        $fields = [];
        if ($this->searchAttributes !== null) {
            foreach ($this->searchAttributes as $key => $value) {
                $fields[$key] = $converter->toPayload($value);
            }
        } else {
            foreach ($this->typedSearchAttributes as $key => $value) {
                $fields[$key->getName()] = $converter->toPayload($value);
            }
        }

        return (new SearchAttributes())->setIndexedFields($fields);
    }

    /**
     * @psalm-suppress DocblockTypeContradiction
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $value
     */
    #[Pure]
    private function withDuration(string $property, mixed $value): self
    {
        if (!DateInterval::assert($value)) {
            throw new \InvalidArgumentException('Duration must be a string, number, or DateInterval.');
        }

        $duration = DateInterval::parse($value, DateInterval::FORMAT_SECONDS);
        if ($duration->totalMicroseconds < 0) {
            throw new \InvalidArgumentException('Duration must not be negative.');
        }

        $clone = clone $this;
        $clone->{$property} = $duration;
        return $clone;
    }
}
