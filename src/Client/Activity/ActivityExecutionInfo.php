<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

use Carbon\CarbonInterval;
use Temporal\Api\Activity\V1\ActivityExecutionListInfo as ProtoActivityExecutionListInfo;
use Temporal\DataConverter\ActivitySerializationContext;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedCollection;
use Temporal\Internal\Support\DateInterval;

/**
 * Visibility information for one standalone Activity execution.
 *
 * @experimental Standalone Activities are a Temporal Server Public Preview feature.
 */
final class ActivityExecutionInfo
{
    public readonly string $activityId;
    public readonly string $runId;
    public readonly string $activityType;
    public readonly int $status;
    public readonly string $taskQueue;
    public readonly ?\DateTimeInterface $scheduleTime;
    public readonly ?\DateTimeInterface $closeTime;
    public readonly ?CarbonInterval $executionDuration;
    public readonly EncodedCollection $searchAttributes;

    public function __construct(
        public readonly ProtoActivityExecutionListInfo $raw,
        DataConverterInterface $converter,
        string $namespace,
    ) {
        $this->activityId = $raw->getActivityId();
        $this->runId = $raw->getRunId();
        $this->activityType = $raw->getActivityType()?->getName() ?? '';
        $this->status = $raw->getStatus();
        $this->taskQueue = $raw->getTaskQueue();
        $this->scheduleTime = $raw->getScheduleTime()?->toDateTime();
        $this->closeTime = $raw->getCloseTime()?->toDateTime();
        $this->executionDuration = $raw->getExecutionDuration() === null
            ? null
            : DateInterval::parse($raw->getExecutionDuration());
        $fields = $raw->getSearchAttributes()?->getIndexedFields() ?? [];
        $this->searchAttributes = EncodedCollection::fromPayloadCollection($fields, $converter)
            ->withSerializationContext(new ActivitySerializationContext(
                namespace: $namespace,
                activityType: $this->activityType,
                taskQueue: $this->taskQueue,
                workflowId: null,
                workflowType: null,
                isLocal: false,
            ));
    }
}
