<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\Activity_result\Success as ActivitySuccess;
use Coresdk\Workflow_activation\WorkflowActivation;
use Coresdk\Workflow_activation\WorkflowActivationJob;
use Coresdk\Workflow_commands\ActivityCancellationType as CoresdkActivityCancellationType;
use Coresdk\Workflow_commands\CompleteWorkflowExecution;
use Coresdk\Workflow_commands\FailWorkflowExecution;
use Coresdk\Workflow_commands\ScheduleActivity;
use Coresdk\Workflow_commands\StartTimer;
use Coresdk\Workflow_commands\WorkflowCommand;
use Coresdk\Workflow_completion\Success;
use Coresdk\Workflow_completion\WorkflowActivationCompletion;
use Google\Protobuf\Duration;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\FailureConverter;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Worker\Transport\Command\CommandInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\Transport\Command\Server\TickInfo;
use Temporal\Worker\Transport\Command\ServerResponseInterface;

/**
 * The coresdk workflow codec: the analog of the RoadRunner Json/Proto codec, but
 * for the Temporal Rust core's strongly-typed protobuf.
 *
 * decode: a coresdk WorkflowActivation (a batch of jobs) becomes the SDK command
 * stream the engine already understands — a server request per job that drives
 * the workflow forward (initialize/signal/query/...), or a server response that
 * resolves a command the workflow previously issued (timer fired, activity
 * resolved, ...).
 *
 * encode: the SDK's outgoing command queue (CompleteWorkflow, NewTimer,
 * ExecuteActivity, ...) becomes a coresdk WorkflowActivationCompletion. Unlike
 * RoadRunner's generic {command, json-options} envelope, each command maps to a
 * specific typed coresdk WorkflowCommand.
 *
 * The run id is carried from decode to encode on the instance: one activation is
 * processed per dispatch cycle, single-threaded, so this is safe.
 *
 * Command/resolution correlation rides on a single number: the SDK stamps every
 * outgoing command with a unique integer id, which we use verbatim as the coresdk
 * `seq`. The core echoes that seq back on the resolution job (fire_timer{seq},
 * resolve_activity{seq}, ...), so we resolve the matching promise by id with no
 * side table.
 *
 * Covered so far: workflow start/completion, timers, and activities. Jobs and
 * commands that are not yet mapped raise so the gap is explicit rather than a
 * silently hung workflow.
 */
final class CoresdkWorkflowCodec implements CodecInterface
{
    private string $runId = '';

    public function __construct(private readonly DataConverterInterface $dataConverter) {}

    public function decode(string $batch, array $headers = []): iterable
    {
        $activation = new WorkflowActivation();
        $activation->mergeFromString($batch);

        $this->runId = $activation->getRunId();
        $taskQueue = (string) ($headers['taskQueue'] ?? '');

        $timestamp = $activation->getTimestamp();
        $tick = new TickInfo(
            time: $timestamp !== null ? $timestamp->toDateTime() : new \DateTimeImmutable(),
            historyLength: $activation->getHistoryLength(),
            historySize: (int) $activation->getHistorySizeBytes(),
            continueAsNewSuggested: $activation->getContinueAsNewSuggested(),
            isReplaying: $activation->getIsReplaying(),
        );

        foreach ($activation->getJobs() as $job) {
            yield $this->decodeJob($job, $tick, $taskQueue);
        }
    }

    public function encode(iterable $commands): string
    {
        $wfCommands = [];
        foreach ($commands as $command) {
            /* The queue also holds acknowledgements for the server requests we
               dispatched (e.g. the StartWorkflow ack). coresdk carries no per-job
               response — only the workflow's own outgoing commands. */
            if (!$command instanceof RequestInterface) {
                continue;
            }

            $wfCommands[] = $this->encodeCommand($command);
        }

        $completion = (new WorkflowActivationCompletion())
            ->setRunId($this->runId)
            ->setSuccessful((new Success())->setCommands($wfCommands));

        return $completion->serializeToString();
    }

    private function decodeJob(WorkflowActivationJob $job, TickInfo $tick, string $taskQueue): CommandInterface
    {
        $variant = $job->getVariant();

        return match ($variant) {
            'initialize_workflow' => $this->startWorkflow($job, $tick, $taskQueue),
            'fire_timer' => $this->fireTimer($job, $tick),
            'resolve_activity' => $this->resolveActivity($job, $tick),
            'remove_from_cache' => $this->removeFromCache($tick),
            default => throw new \RuntimeException("coresdk workflow job not yet supported: {$variant}"),
        };
    }

    /**
     * Evict the run from the worker's cache: the core sends this when it drops a
     * workflow (after completion, or under cache pressure). The engine tears the
     * run's process down via the DestroyWorkflow route; the completion carries no
     * commands.
     */
    private function removeFromCache(TickInfo $tick): ServerRequest
    {
        return new ServerRequest(
            name: 'DestroyWorkflow',
            info: $tick,
            id: $this->runId,
        );
    }

    /**
     * Resolve a timer the workflow previously started. The job carries the seq we
     * stamped on the StartTimer command (= the SDK command id), so the engine's
     * Client::dispatch resolves the matching promise by id.
     */
    private function fireTimer(WorkflowActivationJob $job, TickInfo $tick): SuccessResponse
    {
        return new SuccessResponse(
            values: null,
            id: $job->getFireTimer()->getSeq(),
            info: $tick,
        );
    }

    /**
     * Resolve an activity the workflow scheduled. The resolution is an oneof: a
     * success carries result payloads, a failure or cancellation carries a
     * Temporal failure the engine rejects the promise with. seq maps back to the
     * ScheduleActivity command id.
     */
    private function resolveActivity(WorkflowActivationJob $job, TickInfo $tick): ServerResponseInterface
    {
        $resolve = $job->getResolveActivity();
        $seq = $resolve->getSeq();
        $resolution = $resolve->getResult();

        return match ($resolution->getStatus()) {
            'completed' => $this->activityCompleted($resolution->getCompleted(), $seq, $tick),
            'failed' => new FailureResponse(
                failure: FailureConverter::mapFailureToException(
                    $resolution->getFailed()->getFailure(),
                    $this->dataConverter,
                ),
                id: $seq,
                info: $tick,
            ),
            'cancelled' => new FailureResponse(
                failure: FailureConverter::mapFailureToException(
                    $resolution->getCancelled()->getFailure(),
                    $this->dataConverter,
                ),
                id: $seq,
                info: $tick,
            ),
            default => throw new \RuntimeException(
                "coresdk activity resolution not supported: {$resolution->getStatus()}",
            ),
        };
    }

    private function activityCompleted(ActivitySuccess $success, int $seq, TickInfo $tick): SuccessResponse
    {
        $result = $success->getResult();
        $values = $result !== null
            ? EncodedValues::fromPayloads((new Payloads())->setPayloads([$result]), $this->dataConverter)
            : null;

        return new SuccessResponse(values: $values, id: $seq, info: $tick);
    }

    private function startWorkflow(WorkflowActivationJob $job, TickInfo $tick, string $taskQueue): ServerRequest
    {
        $init = $job->getInitializeWorkflow();

        $payloads = new Payloads();
        $payloads->setPayloads($init->getArguments());

        $options = [
            'info' => [
                'WorkflowType' => ['Name' => $init->getWorkflowType()],
                'WorkflowExecution' => ['ID' => $init->getWorkflowId(), 'RunID' => $this->runId],
                'TaskQueueName' => $taskQueue,
                'Attempt' => $init->getAttempt(),
            ],
        ];

        return new ServerRequest(
            name: 'StartWorkflow',
            info: $tick,
            options: $options,
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
            id: $this->runId,
        );
    }

    private function encodeCommand(RequestInterface $command): WorkflowCommand
    {
        return match ($command->getName()) {
            'CompleteWorkflow' => $this->completeOrFail($command),
            'NewTimer' => $this->startTimer($command),
            'ExecuteActivity' => $this->scheduleActivity($command),
            default => throw new \RuntimeException(
                "SDK workflow command not yet supported: {$command->getName()}",
            ),
        };
    }

    /**
     * Start a timer. seq = the SDK command id, so the later fire_timer job maps
     * straight back to this command's promise.
     */
    private function startTimer(RequestInterface $command): WorkflowCommand
    {
        $ms = (int) ($command->getOptions()['ms'] ?? 0);

        return (new WorkflowCommand())->setStartTimer(
            (new StartTimer())
                ->setSeq($command->getID())
                ->setStartToFireTimeout(self::msToDuration($ms)),
        );
    }

    /**
     * Schedule an activity. seq = the SDK command id; the later resolve_activity
     * job maps back to this command's promise. ActivityOptions reach us already
     * marshalled (PascalCase keys, timeouts in nanoseconds, cancellation as a
     * bool); we translate that into the typed coresdk ScheduleActivity.
     */
    private function scheduleActivity(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $name = (string) ($options['name'] ?? '');
        $ao = $options['options'] ?? [];

        $command->getPayloads()->setDataConverter($this->dataConverter);
        $activityId = (string) ($ao['ActivityID'] ?? '');

        $schedule = (new ScheduleActivity())
            ->setSeq($command->getID())
            ->setActivityId($activityId !== '' ? $activityId : (string) $command->getID())
            ->setActivityType($name)
            ->setTaskQueue((string) ($ao['TaskQueueName'] ?? ''))
            ->setArguments($command->getPayloads()->toPayloads()->getPayloads())
            ->setCancellationType(
                ($ao['WaitForCancellation'] ?? false)
                    ? CoresdkActivityCancellationType::WAIT_CANCELLATION_COMPLETED
                    : CoresdkActivityCancellationType::TRY_CANCEL,
            );

        self::applyActivityTimeouts($schedule, $ao);

        $header = $command->getHeader();
        $header->setDataConverter($this->dataConverter);
        $headers = [];
        foreach ($header->toHeader()->getFields() as $key => $payload) {
            $headers[$key] = $payload;
        }
        if ($headers !== []) {
            $schedule->setHeaders($headers);
        }

        if (\is_array($ao['RetryPolicy'] ?? null)) {
            $schedule->setRetryPolicy(self::retryPolicy($ao['RetryPolicy']));
        }

        return (new WorkflowCommand())->setScheduleActivity($schedule);
    }

    private static function applyActivityTimeouts(ScheduleActivity $schedule, array $ao): void
    {
        if (($ns = (int) ($ao['ScheduleToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setScheduleToCloseTimeout(self::nsToDuration($ns));
        }

        if (($ns = (int) ($ao['ScheduleToStartTimeout'] ?? 0)) > 0) {
            $schedule->setScheduleToStartTimeout(self::nsToDuration($ns));
        }

        if (($ns = (int) ($ao['StartToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setStartToCloseTimeout(self::nsToDuration($ns));
        }

        if (($ns = (int) ($ao['HeartbeatTimeout'] ?? 0)) > 0) {
            $schedule->setHeartbeatTimeout(self::nsToDuration($ns));
        }
    }

    private static function retryPolicy(array $r): RetryPolicy
    {
        $policy = new RetryPolicy();

        $initial = $r['InitialInterval'] ?? $r['initial_interval'] ?? null;
        if (\is_array($initial)) {
            $policy->setInitialInterval(self::durationFromParts($initial));
        }

        $maximum = $r['MaximumInterval'] ?? $r['maximum_interval'] ?? null;
        if (\is_array($maximum)) {
            $policy->setMaximumInterval(self::durationFromParts($maximum));
        }

        $backoff = $r['BackoffCoefficient'] ?? $r['backoff_coefficient'] ?? null;
        if ($backoff !== null) {
            $policy->setBackoffCoefficient((float) $backoff);
        }

        $attempts = $r['MaximumAttempts'] ?? $r['maximum_attempts'] ?? null;
        if ($attempts !== null) {
            $policy->setMaximumAttempts((int) $attempts);
        }

        $nonRetryable = $r['NonRetryableErrorTypes'] ?? $r['non_retryable_error_types'] ?? [];
        if (\is_array($nonRetryable) && $nonRetryable !== []) {
            $policy->setNonRetryableErrorTypes(\array_values($nonRetryable));
        }

        return $policy;
    }

    private static function msToDuration(int $ms): Duration
    {
        return (new Duration())
            ->setSeconds(\intdiv($ms, 1000))
            ->setNanos(($ms % 1000) * 1_000_000);
    }

    private static function nsToDuration(int $ns): Duration
    {
        return (new Duration())
            ->setSeconds(\intdiv($ns, 1_000_000_000))
            ->setNanos($ns % 1_000_000_000);
    }

    private static function durationFromParts(array $d): Duration
    {
        return (new Duration())
            ->setSeconds((int) ($d['seconds'] ?? 0))
            ->setNanos((int) ($d['nanos'] ?? 0));
    }

    private function completeOrFail(RequestInterface $command): WorkflowCommand
    {
        $failure = $command->getFailure();

        if ($failure !== null) {
            return (new WorkflowCommand())->setFailWorkflowExecution(
                (new FailWorkflowExecution())->setFailure(
                    FailureConverter::mapExceptionToFailure($failure, $this->dataConverter),
                ),
            );
        }

        $command->getPayloads()->setDataConverter($this->dataConverter);
        $payloads = $command->getPayloads()->toPayloads()->getPayloads();
        $result = \count($payloads) > 0 ? $payloads[0] : new Payload();

        return (new WorkflowCommand())->setCompleteWorkflowExecution(
            (new CompleteWorkflowExecution())->setResult($result),
        );
    }
}
