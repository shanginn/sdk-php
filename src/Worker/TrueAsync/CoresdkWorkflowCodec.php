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
use Coresdk\Workflow_commands\CancelTimer;
use Coresdk\Workflow_commands\CancelWorkflowExecution;
use Coresdk\Workflow_commands\CompleteWorkflowExecution;
use Coresdk\Workflow_commands\FailWorkflowExecution;
use Coresdk\Workflow_commands\QueryResult;
use Coresdk\Workflow_commands\QuerySuccess;
use Coresdk\Workflow_commands\RequestCancelActivity;
use Coresdk\Workflow_commands\ScheduleActivity;
use Coresdk\Workflow_commands\StartTimer;
use Coresdk\Workflow_commands\WorkflowCommand;
use Coresdk\Workflow_completion\Failure as CompletionFailure;
use Coresdk\Workflow_completion\Success;
use Coresdk\Workflow_completion\WorkflowActivationCompletion;
use Google\Protobuf\Duration;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\Failure\CanceledFailure;
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
 * Command/resolution correlation rides on the coresdk `seq`, which must be stable
 * across replays: the core keys a command by the seq it was issued with, and
 * echoes that seq back on the resolution job (fire_timer{seq}, resolve_activity
 * {seq}, ...). The SDK's own command id cannot be the wire seq — it comes from a
 * process-global counter (Request::$lastID) that keeps growing across runs and
 * replays, so a replayed run re-issues the same logical command with a fresh id
 * and the core's resolution would never match. Instead each run gets a private
 * counter incremented in deterministic command-issue order (the first timer/
 * activity is seq 1, the next seq 2, ...), which a replay reproduces exactly. The
 * codec keeps a per-run seq<->id map so it can stamp outgoing commands with the
 * stable seq and route an incoming resolution back to the current run's live SDK
 * id. The map is dropped when the run is evicted (remove_from_cache); a later
 * replay rebuilds an identical one.
 *
 * Covered so far: workflow start/completion, timers, activities, signals,
 * queries, and cancellation (of the workflow, its timers and its activities).
 * Jobs and commands that are not yet mapped raise so the gap is explicit
 * rather than a silently hung workflow.
 */
final class CoresdkWorkflowCodec implements CodecInterface
{
    private string $runId = '';
    private string $taskQueue = '';

    /**
     * Per-run deterministic seq state, keyed by run id and surviving across the
     * run's activations: ['next' => int, 'idToSeq' => array<int,int>,
     * 'seqToId' => array<int,int>, 'kind' => array<int,string>]. The kind
     * ('timer' | 'activity') is what a later Cancel of the command needs to pick
     * the matching coresdk cancel command.
     *
     * @var array<string, array{next: int, idToSeq: array<int, int>, seqToId: array<int, int>, kind: array<int, string>}>
     */
    private array $runs = [];

    /**
     * Runs the server has asked to cancel (a cancel_workflow job was seen). A
     * CompleteWorkflow carrying a CanceledFailure on such a run encodes as
     * CancelWorkflowExecution; without the flag it stays a plain failure (the
     * workflow threw a CanceledFailure of its own accord).
     *
     * @var array<string, true>
     */
    private array $cancelRequested = [];

    /**
     * coresdk commands staged for the current activation's completion, in issue
     * order; drained by encodeStaged().
     *
     * @var list<WorkflowCommand>
     */
    private array $staged = [];

    /**
     * Query outcomes captured during the current activation (the factory feeds
     * them off the InvokeQuery promises); drained into the completion by encode.
     *
     * @var list<QueryResult>
     */
    private array $queryResults = [];

    public function __construct(private readonly DataConverterInterface $dataConverter) {}

    public function decode(string $batch, array $headers = []): iterable
    {
        $activation = new WorkflowActivation();
        $activation->mergeFromString($batch);

        $this->runId = $activation->getRunId();
        $this->taskQueue = $taskQueue = (string) ($headers['taskQueue'] ?? '');

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
        foreach ($commands as $command) {
            /* The queue also holds acknowledgements for the server requests we
               dispatched (e.g. the StartWorkflow ack). coresdk carries no per-job
               response — only the workflow's own outgoing commands. */
            if ($command instanceof RequestInterface) {
                $this->stage($command);
            }
        }

        return $this->encodeStaged();
    }

    /**
     * Translate one outgoing SDK command into staged coresdk commands (in issue
     * order; emitted by encodeStaged). Returns the SDK command ids that need a
     * *synthetic* cancellation response: the RR host answered a cancelled
     * command itself, but the core never resolves a cancelled timer, so the
     * factory must reject those promises locally and tick again — otherwise a
     * workflow parked on the timer would hang forever.
     *
     * @return list<int>
     */
    public function stage(RequestInterface $command): array
    {
        if ($command->getName() === 'Cancel') {
            return $this->stageCancel($command);
        }

        /* A response arrived for a request the SDK no longer tracks — e.g. a
           timer that fired server-side in the same activation that cancelled
           it. Log-only on the RR host; nothing to tell the core. */
        if ($command->getName() === 'UndefinedResponse') {
            return [];
        }

        $this->staged[] = $this->encodeCommand($command);
        return [];
    }

    /** Build the completion from the staged commands and query results. */
    public function encodeStaged(): string
    {
        $wfCommands = $this->staged;
        $this->staged = [];

        foreach ($this->queryResults as $result) {
            $wfCommands[] = (new WorkflowCommand())->setRespondToQuery($result);
        }
        $this->queryResults = [];

        $completion = (new WorkflowActivationCompletion())
            ->setRunId($this->runId)
            ->setSuccessful((new Success())->setCommands($wfCommands));

        return $completion->serializeToString();
    }

    /**
     * Cancel previously issued commands. Each id maps back to its seq and kind;
     * an unmapped id was pulled from the queue before it ever reached the core
     * (cancelled in the tick that issued it), so there is nothing to cancel.
     * Timer ids are returned for synthetic rejection (see stage); an activity
     * cancel is resolved by the core itself (resolve_activity{cancelled}).
     *
     * @return list<int>
     */
    private function stageCancel(RequestInterface $command): array
    {
        $synthesize = [];
        $run = $this->runs[$this->runId] ?? null;

        foreach ((array) ($command->getOptions()['ids'] ?? []) as $id) {
            $seq = $run['idToSeq'][$id] ?? null;
            if ($seq === null) {
                continue;
            }

            $kind = $run['kind'][$seq] ?? '';
            if ($kind === 'timer') {
                $this->staged[] = (new WorkflowCommand())->setCancelTimer(
                    (new CancelTimer())->setSeq($seq),
                );
                $synthesize[] = (int) $id;
            } elseif ($kind === 'activity') {
                $this->staged[] = (new WorkflowCommand())->setRequestCancelActivity(
                    (new RequestCancelActivity())->setSeq($seq),
                );
            } else {
                throw new \RuntimeException(
                    "cancel of a '{$kind}' command not yet supported (seq {$seq})",
                );
            }
        }

        return $synthesize;
    }

    /**
     * Build a failed activation completion for the current run. Used when applying
     * the activation throws (a codec gap, an unmapped resolution, an engine or
     * workflow-code error): reporting the workflow-task failure lets the core retry
     * the task instead of leaving it to time out with no completion. force_cause is
     * left unspecified, so the server treats it as a normal, retryable task failure
     * rather than failing the workflow.
     */
    public function encodeFailure(\Throwable $e): string
    {
        /* A failed task reports no partial results or commands. */
        $this->queryResults = [];
        $this->staged = [];

        $completion = (new WorkflowActivationCompletion())
            ->setRunId($this->runId)
            ->setFailed(
                (new CompletionFailure())->setFailure(
                    FailureConverter::mapExceptionToFailure($e, $this->dataConverter),
                ),
            );

        return $completion->serializeToString();
    }

    private function decodeJob(WorkflowActivationJob $job, TickInfo $tick, string $taskQueue): CommandInterface
    {
        $variant = $job->getVariant();

        return match ($variant) {
            'initialize_workflow' => $this->startWorkflow($job, $tick, $taskQueue),
            'signal_workflow' => $this->signalWorkflow($job, $tick),
            'query_workflow' => $this->queryWorkflow($job, $tick),
            'cancel_workflow' => $this->cancelWorkflow($tick),
            'fire_timer' => $this->fireTimer($job, $tick),
            'resolve_activity' => $this->resolveActivity($job, $tick),
            'remove_from_cache' => $this->removeFromCache($tick),
            default => throw new \RuntimeException("coresdk workflow job not yet supported: {$variant}"),
        };
    }

    /**
     * The server asked to cancel the workflow. The CancelWorkflow route cancels
     * the run's root scope: in-flight commands get Cancel requests, and the
     * workflow observes a CanceledFailure at its current await (delivered by a
     * core resolution for activities, or synthesized by the factory for
     * timers). The flag makes the resulting CanceledFailure completion encode
     * as CancelWorkflowExecution rather than a plain failure.
     */
    private function cancelWorkflow(TickInfo $tick): ServerRequest
    {
        $this->cancelRequested[$this->runId] = true;

        return new ServerRequest(
            name: 'CancelWorkflow',
            info: $tick,
            id: $this->runId,
        );
    }

    /**
     * Evict the run from the worker's cache: the core sends this when it drops a
     * workflow (after completion, or under cache pressure). The engine tears the
     * run's process down via the DestroyWorkflow route; the completion carries no
     * commands. Drop the seq map too so a later replay rebuilds it from scratch.
     */
    private function removeFromCache(TickInfo $tick): ServerRequest
    {
        $request = new ServerRequest(
            name: 'DestroyWorkflow',
            info: $tick,
            id: $this->runId,
        );

        unset($this->runs[$this->runId], $this->cancelRequested[$this->runId]);

        return $request;
    }

    /**
     * Resolve a timer the workflow previously started. The job carries the stable
     * seq we stamped on the StartTimer command; mapping it back to the current
     * run's SDK command id lets Client::dispatch resolve the matching promise.
     */
    private function fireTimer(WorkflowActivationJob $job, TickInfo $tick): SuccessResponse
    {
        return new SuccessResponse(
            values: null,
            id: $this->idForSeq($job->getFireTimer()->getSeq()),
            info: $tick,
        );
    }

    /**
     * Resolve an activity the workflow scheduled. The resolution is an oneof: a
     * success carries result payloads, a failure or cancellation carries a
     * Temporal failure the engine rejects the promise with. The job's seq maps
     * back to the current run's ScheduleActivity command id.
     */
    private function resolveActivity(WorkflowActivationJob $job, TickInfo $tick): ServerResponseInterface
    {
        $resolve = $job->getResolveActivity();
        $id = $this->idForSeq($resolve->getSeq());
        $resolution = $resolve->getResult();

        return match ($resolution->getStatus()) {
            'completed' => $this->activityCompleted($resolution->getCompleted(), $id, $tick),
            'failed' => new FailureResponse(
                failure: FailureConverter::mapFailureToException(
                    $resolution->getFailed()->getFailure(),
                    $this->dataConverter,
                ),
                id: $id,
                info: $tick,
            ),
            'cancelled' => new FailureResponse(
                failure: FailureConverter::mapFailureToException(
                    $resolution->getCancelled()->getFailure(),
                    $this->dataConverter,
                ),
                id: $id,
                info: $tick,
            ),
            default => throw new \RuntimeException(
                "coresdk activity resolution not supported: {$resolution->getStatus()}",
            ),
        };
    }

    private function activityCompleted(ActivitySuccess $success, int $id, TickInfo $tick): SuccessResponse
    {
        $result = $success->getResult();
        $values = $result !== null
            ? EncodedValues::fromPayloads((new Payloads())->setPayloads([$result]), $this->dataConverter)
            : null;

        return new SuccessResponse(values: $values, id: $id, info: $tick);
    }

    /**
     * Map an SDK command id to this run's deterministic seq, assigning the next
     * one on first sight. Stable across replays because the workflow issues its
     * commands in the same order every time, unlike the process-global id. The
     * kind is remembered so a later Cancel of the id picks the matching coresdk
     * cancel command.
     */
    private function seqFor(int $commandId, string $kind): int
    {
        $run = &$this->runs[$this->runId];
        $run ??= ['next' => 1, 'idToSeq' => [], 'seqToId' => [], 'kind' => []];

        if (isset($run['idToSeq'][$commandId])) {
            return $run['idToSeq'][$commandId];
        }

        $seq = $run['next']++;
        $run['idToSeq'][$commandId] = $seq;
        $run['seqToId'][$seq] = $commandId;
        $run['kind'][$seq] = $kind;

        return $seq;
    }

    /**
     * Map a resolution job's stable seq back to the current run's live SDK command
     * id. The command was issued (and mapped) on an earlier activation of this run:
     * the core replays one workflow task per activation, so a resolution never
     * arrives before the activation that issues its command. An unmapped seq is a
     * real defect, so raise rather than resolve the wrong promise.
     */
    private function idForSeq(int $seq): int
    {
        $run = $this->runs[$this->runId] ?? null;

        if ($run === null || !isset($run['seqToId'][$seq])) {
            throw new \RuntimeException(
                "no workflow command mapped to resolution seq {$seq} on run {$this->runId}",
            );
        }

        return $run['seqToId'][$seq];
    }

    /**
     * Run a query against the workflow. The request id must be the run id (the
     * InvokeQuery route looks the process up by it), which makes the generic ack
     * queue unable to tell a query result apart from other acks — so the factory
     * dispatches this request outside the Server, captures the outcome off the
     * promise into recordQuery*, and encode() emits the QueryResult command with
     * the completion. Queries never advance the workflow: no seq, no commands.
     */
    private function queryWorkflow(WorkflowActivationJob $job, TickInfo $tick): ServerRequest
    {
        $query = $job->getQueryWorkflow();

        $payloads = new Payloads();
        $payloads->setPayloads(\iterator_to_array($query->getArguments()));

        return new QueryServerRequest(
            queryId: $query->getQueryId(),
            name: 'InvokeQuery',
            info: $tick,
            options: ['name' => $query->getQueryType()],
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
            id: $this->runId,
        );
    }

    /** Record a resolved query; emitted as a QueryResult command by encode(). */
    public function recordQuerySuccess(string $queryId, ?ValuesInterface $values): void
    {
        if ($values instanceof EncodedValues) {
            $values->setDataConverter($this->dataConverter);
        }

        $payloads = $values?->toPayloads()->getPayloads();
        $response = $payloads !== null && \count($payloads) > 0 ? $payloads[0] : new Payload();

        $this->queryResults[] = (new QueryResult())
            ->setQueryId($queryId)
            ->setSucceeded((new QuerySuccess())->setResponse($response));
    }

    /** Record a failed query (unknown type, handler threw); see encode(). */
    public function recordQueryFailure(string $queryId, \Throwable $error): void
    {
        $this->queryResults[] = (new QueryResult())
            ->setQueryId($queryId)
            ->setFailed(FailureConverter::mapExceptionToFailure($error, $this->dataConverter));
    }

    /**
     * Deliver a signal to a running workflow. An advancing job (no seq): the
     * InvokeSignal route finds the run by id and hands the input to the registered
     * signal handler, which may unblock an awaitSignal and let the workflow issue
     * its next commands on the following tick.
     */
    private function signalWorkflow(WorkflowActivationJob $job, TickInfo $tick): ServerRequest
    {
        $signal = $job->getSignalWorkflow();

        $payloads = new Payloads();
        $payloads->setPayloads($signal->getInput());

        return new ServerRequest(
            name: 'InvokeSignal',
            info: $tick,
            options: ['name' => $signal->getSignalName()],
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
            id: $this->runId,
        );
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
     * Start a timer. seq is this run's deterministic sequence number for the
     * command, so the later fire_timer job maps back to its promise across replays.
     */
    private function startTimer(RequestInterface $command): WorkflowCommand
    {
        $ms = (int) ($command->getOptions()['ms'] ?? 0);

        return (new WorkflowCommand())->setStartTimer(
            (new StartTimer())
                ->setSeq($this->seqFor($command->getID(), 'timer'))
                ->setStartToFireTimeout(self::msToDuration($ms)),
        );
    }

    /**
     * Schedule an activity. seq is this run's deterministic sequence number for
     * the command; the later resolve_activity job maps back to its promise across
     * replays. The activity id defaults to the same seq (also replay-stable) when
     * the workflow sets none. ActivityOptions reach us already marshalled
     * (PascalCase keys, timeouts in nanoseconds, cancellation as a bool); we
     * translate that into the typed coresdk ScheduleActivity.
     */
    private function scheduleActivity(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $name = (string) ($options['name'] ?? '');
        $ao = $options['options'] ?? [];

        $command->getPayloads()->setDataConverter($this->dataConverter);
        $seq = $this->seqFor($command->getID(), 'activity');
        $activityId = (string) ($ao['ActivityID'] ?? '');
        $taskQueue = (string) ($ao['TaskQueueName'] ?? '');

        $schedule = (new ScheduleActivity())
            ->setSeq($seq)
            ->setActivityId($activityId !== '' ? $activityId : (string) $seq)
            ->setActivityType($name)
            // An activity inherits the workflow's task queue when none is set.
            ->setTaskQueue($taskQueue !== '' ? $taskQueue : $this->taskQueue)
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
            /* A CanceledFailure on a run the server asked to cancel is the
               normal end of a cancelled workflow, not an error. A CanceledFailure
               the workflow threw of its own accord stays a failure. */
            if ($failure instanceof CanceledFailure && isset($this->cancelRequested[$this->runId])) {
                return (new WorkflowCommand())->setCancelWorkflowExecution(new CancelWorkflowExecution());
            }

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
