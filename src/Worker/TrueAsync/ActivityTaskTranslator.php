<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\Activity_result\ActivityExecutionResult;
use Coresdk\Activity_result\Cancellation;
use Coresdk\Activity_result\Failure as ResultFailure;
use Coresdk\Activity_result\Success;
use Coresdk\Activity_result\WillCompleteAsync;
use Coresdk\Activity_task\ActivityTask;
use Coresdk\Activity_task\Start;
use Coresdk\ActivityTaskCompletion;
use Google\Protobuf\Timestamp;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\DoNotCompleteOnResultException;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\FailureConverter;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\TickInfo;

/**
 * Translates between the coresdk bridge protobuf (what the Rust core hands us)
 * and the SDK's internal command shape (what the activity Router consumes).
 *
 * Inbound: a coresdk ActivityTask.Start becomes an "InvokeActivity" ServerRequest
 * carrying the activity info, input payloads and header — the exact shape the
 * RoadRunner transport used to build, so InvokeActivity and ActivityContext are
 * reused unchanged. Outbound: the activity's resolved value or thrown error
 * becomes a coresdk ActivityTaskCompletion.
 */
final class ActivityTaskTranslator
{
    public function __construct(
        private readonly DataConverterInterface $dataConverter,
        private readonly string $taskQueue,
    ) {}

    /**
     * Build the "InvokeActivity" request from a Start task, or null when the
     * task is a cancel (handled out of band, not through the Router).
     */
    public function toServerRequest(ActivityTask $task): ?ServerRequest
    {
        if ($task->getVariant() !== 'start') {
            return null;
        }

        $start = $task->getStart();

        /* Heartbeat details ride at the tail of the payload list; InvokeActivity
           slices off the last N, mirroring the RoadRunner convention. */
        $input = \iterator_to_array($start->getInput());
        $heartbeat = \iterator_to_array($start->getHeartbeatDetails());

        $payloads = new Payloads();
        $payloads->setPayloads(\array_merge($input, $heartbeat));

        $options = [
            'info' => $this->info($start, $task->getTaskToken()),
            'heartbeatDetails' => \count($heartbeat),
        ];

        return new ServerRequest(
            name: 'InvokeActivity',
            info: new TickInfo(time: $this->time($start->getStartedTime()) ?? new \DateTimeImmutable()),
            options: $options,
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
        );
    }

    /** A successful completion carrying the activity's single return value. */
    public function success(string $taskToken, ValuesInterface $result): ActivityTaskCompletion
    {
        /* InvokeActivity resolves with raw, unencoded values; bind our converter
           so toPayloads() can serialize them. */
        if ($result instanceof EncodedValues) {
            $result->setDataConverter($this->dataConverter);
        }

        $payloads = $result->toPayloads()->getPayloads();
        $value = \count($payloads) > 0 ? $payloads[0] : new Payload();

        return $this->completion(
            $taskToken,
            (new ActivityExecutionResult())->setCompleted((new Success())->setResult($value)),
        );
    }

    /**
     * A non-successful completion: a do-not-complete request, a cancellation, or
     * a plain failure, picked by the thrown exception type.
     */
    public function failure(string $taskToken, \Throwable $error): ActivityTaskCompletion
    {
        if ($error instanceof DoNotCompleteOnResultException) {
            return $this->completion(
                $taskToken,
                (new ActivityExecutionResult())->setWillCompleteAsync(new WillCompleteAsync()),
            );
        }

        $failure = FailureConverter::mapExceptionToFailure($error, $this->dataConverter);

        $result = $error instanceof CanceledFailure
            ? (new ActivityExecutionResult())->setCancelled((new Cancellation())->setFailure($failure))
            : (new ActivityExecutionResult())->setFailed((new ResultFailure())->setFailure($failure));

        return $this->completion($taskToken, $result);
    }

    private function completion(string $taskToken, ActivityExecutionResult $result): ActivityTaskCompletion
    {
        return (new ActivityTaskCompletion())
            ->setTaskToken($taskToken)
            ->setResult($result);
    }

    /**
     * The ActivityInfo map, keyed by the marshaller's field names. The token is
     * re-encoded as base64 because InvokeActivity base64-decodes it back to the
     * raw bytes the heartbeat/completion calls expect.
     */
    private function info(Start $start, string $taskToken): array
    {
        $execution = $start->getWorkflowExecution();

        return [
            'TaskToken' => \base64_encode($taskToken),
            'WorkflowNamespace' => $start->getWorkflowNamespace(),
            'WorkflowType' => ['Name' => $start->getWorkflowType()],
            'WorkflowExecution' => [
                'ID' => $execution?->getWorkflowId() ?? '',
                'RunID' => $execution?->getRunId() ?? '',
            ],
            'ActivityID' => $start->getActivityId(),
            'ActivityType' => ['Name' => $start->getActivityType()],
            'TaskQueue' => $this->taskQueue,
            'HeartbeatTimeout' => $start->getHeartbeatTimeout(),
            'ScheduledTime' => $this->time($start->getScheduledTime()),
            'StartedTime' => $this->time($start->getStartedTime()),
            'Deadline' => $this->deadline($start),
            'Attempt' => $start->getAttempt(),
        ];
    }

    /** Per-attempt deadline = started + start_to_close, falling back to started. */
    private function deadline(Start $start): ?\DateTimeInterface
    {
        $started = $this->time($start->getStartedTime());
        $timeout = $start->getStartToCloseTimeout();

        if ($started === null || $timeout === null) {
            return $started;
        }

        return (new \DateTimeImmutable())
            ->setTimestamp($started->getTimestamp() + $timeout->getSeconds());
    }

    private function time(?Timestamp $ts): ?\DateTimeInterface
    {
        return $ts === null ? null : $ts->toDateTime();
    }
}
