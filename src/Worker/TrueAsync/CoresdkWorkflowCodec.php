<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\ActivityResult\Success as ActivitySuccess;
use Coresdk\ChildWorkflow\ChildWorkflowCancellationType as CoresdkChildCancellationType;
use Coresdk\Common\NamespacedWorkflowExecution;
use Coresdk\WorkflowActivation\WorkflowActivation;
use Coresdk\WorkflowActivation\WorkflowActivationJob;
use Coresdk\WorkflowCommands\ActivityCancellationType as CoresdkActivityCancellationType;
use Coresdk\WorkflowCommands\CancelChildWorkflowExecution;
use Coresdk\WorkflowCommands\CancelTimer;
use Coresdk\WorkflowCommands\CancelWorkflowExecution;
use Coresdk\WorkflowCommands\CompleteWorkflowExecution;
use Coresdk\WorkflowCommands\ContinueAsNewWorkflowExecution;
use Coresdk\WorkflowCommands\FailWorkflowExecution;
use Coresdk\WorkflowCommands\ModifyWorkflowProperties;
use Coresdk\WorkflowCommands\QueryResult;
use Coresdk\WorkflowCommands\QuerySuccess;
use Coresdk\WorkflowCommands\RequestCancelActivity;
use Coresdk\WorkflowCommands\RequestCancelExternalWorkflowExecution;
use Coresdk\WorkflowCommands\RequestCancelLocalActivity;
use Coresdk\WorkflowCommands\ScheduleActivity;
use Coresdk\WorkflowCommands\ScheduleLocalActivity;
use Coresdk\WorkflowCommands\SignalExternalWorkflowExecution;
use Coresdk\WorkflowCommands\StartChildWorkflowExecution;
use Coresdk\WorkflowCommands\StartTimer;
use Coresdk\WorkflowCommands\UpdateResponse as CoresdkUpdateResponse;
use Coresdk\WorkflowCommands\UpsertWorkflowSearchAttributes;
use Coresdk\WorkflowCommands\WorkflowCommand;
use Coresdk\WorkflowCompletion\Failure as CompletionFailure;
use Coresdk\WorkflowCompletion\Success;
use Coresdk\WorkflowCompletion\WorkflowActivationCompletion;
use Google\Protobuf\Duration;
use Google\Protobuf\GPBEmpty;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\Api\Common\V1\SearchAttributes;
use Temporal\Api\Failure\V1\Failure;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\FailureConverter;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Worker\Transport\Command\Client\UpdateResponse;
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
 * Covered so far: workflow start/completion, timers, activities (regular and
 * local), signals, queries, cancellation (of the workflow, its timers,
 * activities and child workflows), child workflows, continue-as-new, signalling
 * and cancelling external/child workflows, updates (validate/accept/reject/
 * complete), upserting search attributes (untyped and typed) and memo, and panic
 * (a retryable workflow error reported as a failed task). Jobs and commands that
 * are not yet mapped raise so the gap is explicit rather than a silently hung
 * workflow.
 */
final class CoresdkWorkflowCodec implements CodecInterface
{
    private string $runId = '';
    private string $taskQueue = '';

    /**
     * Per-run deterministic seq state, keyed by run id and surviving across the
     * run's activations. 'kind' ('timer' | 'activity' | 'child-workflow') is
     * what a later Cancel of the command needs to pick the matching coresdk
     * cancel command. 'wfId' is this run's workflow id (the deterministic
     * default for child workflow ids). 'children' tracks per-seq child state:
     * the child's workflow id and the pending GetChildWorkflowExecution
     * request id its start resolution must answer. 'updates' maps each update id
     * to its protocol_instance_id, which the coresdk UpdateResponse needs but the
     * SDK's UpdateResponse command does not carry.
     *
     * @var array<string, array{
     *     next: int,
     *     idToSeq: array<int, int>,
     *     seqToId: array<int, int>,
     *     kind: array<int, string>,
     *     wfId: string,
     *     children: array<int, array{wfId: string, getId: int|null}>,
     *     updates: array<string, string>,
     * }>
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

    /**
     * A Panic command staged during the current activation: the engine issues one
     * when workflow code throws a *retryable* error (see Process::complete). That
     * is a workflow-*task* failure, not a workflow failure — the whole activation
     * completes as failed (so the core retries the task), discarding any commands
     * queued before it, exactly as the RoadRunner host does. Captured here and
     * turned into the failed completion by {@see encodeStaged}.
     */
    private ?\Throwable $panic = null;

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
            yield from $this->decodeJob($job, $tick, $taskQueue);
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

        /* Not a coresdk command: a Panic fails the whole workflow task. Capture
           the failure; encodeStaged() turns the activation into a failed
           completion and drops everything staged before it. */
        if ($command->getName() === 'Panic') {
            $this->panic = $command->getFailure() ?? new \RuntimeException('workflow panicked');
            return [];
        }

        /* No coresdk command: the start confirmation arrives on its own
           (resolve_child_workflow_execution_start). Remember which request id
           that resolution must answer. The ExecuteChildWorkflow is always
           staged first (the stub issues both in order), so the seq exists. */
        if ($command->getName() === 'GetChildWorkflowExecution') {
            $executeId = (int) ($command->getOptions()['id'] ?? 0);
            $run = &$this->run();
            $seq = $run['idToSeq'][$executeId]
                ?? throw new \RuntimeException("GetChildWorkflowExecution for unknown command id {$executeId}");
            $run['children'][$seq]['getId'] = $command->getID();
            return [];
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
        /* A Panic staged this activation overrides everything: report a failed
           task and discard the partial command stream (encodeFailure clears it). */
        if ($this->panic !== null) {
            return $this->encodeFailure($this->panic);
        }

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
            } elseif ($kind === 'local-activity') {
                /* The core resolves the local activity (cancelled) itself. */
                $this->staged[] = (new WorkflowCommand())->setRequestCancelLocalActivity(
                    (new RequestCancelLocalActivity())->setSeq($seq),
                );
            } elseif ($kind === 'child-workflow') {
                /* The core resolves the child (cancelled) itself; no synthesis. */
                $this->staged[] = (new WorkflowCommand())->setCancelChildWorkflowExecution(
                    (new CancelChildWorkflowExecution())->setChildWorkflowSeq($seq),
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
        $this->panic = null;

        $completion = (new WorkflowActivationCompletion())
            ->setRunId($this->runId)
            ->setFailed(
                (new CompletionFailure())->setFailure(
                    FailureConverter::mapExceptionToFailure($e, $this->dataConverter),
                ),
            );

        return $completion->serializeToString();
    }

    /**
     * One job usually yields one engine command, but a few yield more (a failed
     * child start must reject both the start waiter and the result promise — the
     * core sends nothing further for that seq), hence the list.
     *
     * @return list<CommandInterface>
     */
    private function decodeJob(WorkflowActivationJob $job, TickInfo $tick, string $taskQueue): array
    {
        $variant = $job->getVariant();

        return match ($variant) {
            'initialize_workflow' => [$this->startWorkflow($job, $tick, $taskQueue)],
            'signal_workflow' => [$this->signalWorkflow($job, $tick)],
            'query_workflow' => [$this->queryWorkflow($job, $tick)],
            'cancel_workflow' => [$this->cancelWorkflow($tick)],
            'fire_timer' => [$this->fireTimer($job, $tick)],
            'resolve_activity' => [$this->resolveActivity($job, $tick)],
            'resolve_child_workflow_execution_start' => $this->resolveChildStart($job, $tick),
            'resolve_child_workflow_execution' => [$this->resolveChild($job, $tick)],
            'resolve_signal_external_workflow' => [$this->resolveSignalExternal($job, $tick)],
            'resolve_request_cancel_external_workflow' => [$this->resolveCancelExternal($job, $tick)],
            'do_update' => [$this->doUpdate($job, $tick)],
            'remove_from_cache' => [$this->removeFromCache($tick)],
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
     * The child workflow's start resolved. On success only the start waiter
     * (GetChildWorkflowExecution) is answered — the result promise resolves
     * later via resolve_child_workflow_execution. On failure or pre-start
     * cancellation the core sends nothing further for this seq, so BOTH the
     * waiter and the result promise must be rejected here.
     *
     * @return list<CommandInterface>
     */
    private function resolveChildStart(WorkflowActivationJob $job, TickInfo $tick): array
    {
        $resolve = $job->getResolveChildWorkflowExecutionStart();
        $seq = $resolve->getSeq();
        $executeId = $this->idForSeq($seq);

        $run = &$this->run();
        $child = $run['children'][$seq] ?? null;
        $getId = $child['getId'] ?? null;
        if ($getId === null) {
            throw new \RuntimeException("no start waiter registered for child workflow seq {$seq}");
        }
        $run['children'][$seq]['getId'] = null;

        switch ($resolve->getStatus()) {
            case 'succeeded':
                /* The keys follow WorkflowExecution's Marshal names: the stub
                   hydrates the value via getValue(0, WorkflowExecution::class). */
                $execution = [
                    'ID' => $child['wfId'] ?? '',
                    'RunID' => $resolve->getSucceeded()->getRunId(),
                ];

                return [new SuccessResponse(
                    values: EncodedValues::fromValues([$execution], $this->dataConverter),
                    id: $getId,
                    info: $tick,
                )];

            case 'failed':
                $failed = $resolve->getFailed();
                $failure = new ApplicationFailure(
                    \sprintf(
                        "child workflow start failed: %s '%s' (cause %d)",
                        $failed->getWorkflowType(),
                        $failed->getWorkflowId(),
                        $failed->getCause(),
                    ),
                    'ChildWorkflowExecutionStartFailure',
                    true,
                );

                return [
                    new FailureResponse(failure: $failure, id: $getId, info: $tick),
                    new FailureResponse(failure: $failure, id: $executeId, info: $tick),
                ];

            case 'cancelled':
                $failure = FailureConverter::mapFailureToException(
                    $resolve->getCancelled()->getFailure(),
                    $this->dataConverter,
                );

                return [
                    new FailureResponse(failure: $failure, id: $getId, info: $tick),
                    new FailureResponse(failure: $failure, id: $executeId, info: $tick),
                ];
        }

        throw new \RuntimeException(
            "coresdk child workflow start resolution not supported: {$resolve->getStatus()}",
        );
    }

    /** The child workflow itself resolved: answer the ExecuteChildWorkflow promise. */
    private function resolveChild(WorkflowActivationJob $job, TickInfo $tick): ServerResponseInterface
    {
        $resolve = $job->getResolveChildWorkflowExecution();
        $id = $this->idForSeq($resolve->getSeq());
        $result = $resolve->getResult();

        switch ($result->getStatus()) {
            case 'completed':
                $payload = $result->getCompleted()->getResult();
                $values = $payload !== null
                    ? EncodedValues::fromPayloads((new Payloads())->setPayloads([$payload]), $this->dataConverter)
                    : null;

                return new SuccessResponse(values: $values, id: $id, info: $tick);

            case 'failed':
                return new FailureResponse(
                    failure: FailureConverter::mapFailureToException(
                        $result->getFailed()->getFailure(),
                        $this->dataConverter,
                    ),
                    id: $id,
                    info: $tick,
                );

            case 'cancelled':
                return new FailureResponse(
                    failure: FailureConverter::mapFailureToException(
                        $result->getCancelled()->getFailure(),
                        $this->dataConverter,
                    ),
                    id: $id,
                    info: $tick,
                );
        }

        throw new \RuntimeException(
            "coresdk child workflow resolution not supported: {$result->getStatus()}",
        );
    }

    /**
     * The signal we sent to an external (or child) workflow was delivered, or
     * failed.
     */
    private function resolveSignalExternal(WorkflowActivationJob $job, TickInfo $tick): ServerResponseInterface
    {
        $resolve = $job->getResolveSignalExternalWorkflow();

        return $this->resolveExternalOp($resolve->getSeq(), $resolve->getFailure(), $tick);
    }

    /**
     * The cancellation we requested of an external workflow was delivered, or
     * failed (e.g. the target no longer exists).
     */
    private function resolveCancelExternal(WorkflowActivationJob $job, TickInfo $tick): ServerResponseInterface
    {
        $resolve = $job->getResolveRequestCancelExternalWorkflow();

        return $this->resolveExternalOp($resolve->getSeq(), $resolve->getFailure(), $tick);
    }

    /**
     * Resolve an external-workflow op (signal or cancel): both carry the stable
     * seq we stamped on the command and an optional failure. The seq maps back to
     * the command id; a failure rejects the promise, a success resolves it with no
     * value (neither op returns a result payload).
     */
    private function resolveExternalOp(int $seq, ?Failure $failure, TickInfo $tick): ServerResponseInterface
    {
        $id = $this->idForSeq($seq);

        if ($failure !== null) {
            return new FailureResponse(
                failure: FailureConverter::mapFailureToException($failure, $this->dataConverter),
                id: $id,
                info: $tick,
            );
        }

        return new SuccessResponse(values: null, id: $id, info: $tick);
    }

    /**
     * A workflow update was delivered. The InvokeUpdate route runs the validator
     * (unless run_validator is false — replay/already-accepted) and the handler,
     * emitting one or more UpdateResponse commands the factory feeds back through
     * {@see stageUpdateResponse}. The coresdk UpdateResponse needs the update's
     * protocol_instance_id, so it is remembered here keyed by update id (the SDK's
     * UpdateResponse command only carries the update id). Like signals/queries the
     * request id is the run id and the update advances no command seq of its own.
     */
    private function doUpdate(WorkflowActivationJob $job, TickInfo $tick): ServerRequest
    {
        $update = $job->getDoUpdate();
        $updateId = $update->getId();

        $this->run()['updates'][$updateId] = $update->getProtocolInstanceId();

        $payloads = new Payloads();
        $payloads->setPayloads(\iterator_to_array($update->getInput()));

        return new ServerRequest(
            name: 'InvokeUpdate',
            info: $tick,
            options: [
                'updateId' => $updateId,
                'name' => $update->getName(),
                /* run_validator false means the core already accepted it (replay
                   or a no-validator update); InvokeUpdate skips validation then. */
                'replay' => !$update->getRunValidator(),
            ],
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
            id: $this->runId,
        );
    }

    /**
     * Translate one SDK UpdateResponse (the validation or completion phase) into a
     * coresdk UpdateResponse command, stamped with the update's protocol instance.
     * A failure — a rejected validator or a handler that threw after acceptance —
     * is 'rejected' either way (per the core protocol); a passed validation is
     * 'accepted'; a successful handler is 'completed' with its single result. The
     * factory routes these here because UpdateResponse is a ResponseInterface, not
     * a RequestInterface, so it never reaches {@see stage}.
     */
    public function stageUpdateResponse(UpdateResponse $response): void
    {
        $updateId = (string) ($response->getOptions()['id'] ?? '');
        $protocolInstanceId = $this->runs[$this->runId]['updates'][$updateId]
            ?? throw new \RuntimeException(
                "no protocol instance for update {$updateId} on run {$this->runId}",
            );

        $update = (new CoresdkUpdateResponse())->setProtocolInstanceId($protocolInstanceId);

        $failure = $response->getFailure();
        if ($failure !== null) {
            $update->setRejected(FailureConverter::mapExceptionToFailure($failure, $this->dataConverter));
        } elseif ($response->getCommand() === UpdateResponse::COMMAND_VALIDATED) {
            $update->setAccepted(new GPBEmpty());
        } else {
            $values = $response->getPayloads();
            if ($values instanceof EncodedValues) {
                $values->setDataConverter($this->dataConverter);
            }
            $payloads = $values?->toPayloads()->getPayloads();
            $result = $payloads !== null && \count($payloads) > 0 ? $payloads[0] : new Payload();
            $update->setCompleted($result);
        }

        $this->staged[] = (new WorkflowCommand())->setUpdateResponse($update);
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
        $run = &$this->run();

        if (isset($run['idToSeq'][$commandId])) {
            return $run['idToSeq'][$commandId];
        }

        $seq = $run['next']++;
        $run['idToSeq'][$commandId] = $seq;
        $run['seqToId'][$seq] = $commandId;
        $run['kind'][$seq] = $kind;

        return $seq;
    }

    /** The current run's seq state, created on first touch. */
    private function &run(): array
    {
        $run = &$this->runs[$this->runId];
        $run ??= ['next' => 1, 'idToSeq' => [], 'seqToId' => [], 'kind' => [], 'wfId' => '', 'children' => [], 'updates' => []];

        return $run;
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

        /* Remembered as the base of deterministic child workflow ids. */
        $this->run()['wfId'] = $init->getWorkflowId();

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
            'ExecuteLocalActivity' => $this->scheduleLocalActivity($command),
            'ExecuteChildWorkflow' => $this->startChildWorkflow($command),
            'SignalExternalWorkflow' => $this->signalExternalWorkflow($command),
            'CancelExternalWorkflow' => $this->cancelExternalWorkflow($command),
            'UpsertWorkflowSearchAttributes' => $this->upsertSearchAttributes($command),
            'UpsertWorkflowTypedSearchAttributes' => $this->upsertTypedSearchAttributes($command),
            'UpsertMemo' => $this->upsertMemo($command),
            'ContinueAsNew' => $this->continueAsNew($command),
            default => throw new \RuntimeException(
                "SDK workflow command not yet supported: {$command->getName()}",
            ),
        };
    }

    /**
     * Start a child workflow. The child's workflow id must be replay-stable, so
     * when the options carry none it defaults to "<parent workflow id>_<seq>"
     * (the SDK command id is process-global and replay-unstable). The start
     * confirmation and the result arrive as separate resolution jobs; the
     * GetChildWorkflowExecution waiter for the former is registered when that
     * command is staged (see stage()).
     */
    private function startChildWorkflow(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $co = $options['options'] ?? [];
        $seq = $this->seqFor($command->getID(), 'child-workflow');
        $run = &$this->run();

        $workflowId = (string) ($co['WorkflowID'] ?? '');
        if ($workflowId === '') {
            $workflowId = $run['wfId'] . '_' . $seq;
        }
        $run['children'][$seq] = ['wfId' => $workflowId, 'getId' => null];

        $command->getPayloads()->setDataConverter($this->dataConverter);
        $taskQueue = (string) ($co['TaskQueueName'] ?? '');

        $start = (new StartChildWorkflowExecution())
            ->setSeq($seq)
            ->setNamespace((string) ($co['Namespace'] ?? 'default'))
            ->setWorkflowId($workflowId)
            ->setWorkflowType((string) ($options['name'] ?? ''))
            /* A child inherits the parent's task queue when none is set. */
            ->setTaskQueue($taskQueue !== '' ? $taskQueue : $this->taskQueue)
            ->setInput($command->getPayloads()->toPayloads()->getPayloads())
            ->setParentClosePolicy((int) ($co['ParentClosePolicy'] ?? 0))
            ->setWorkflowIdReusePolicy((int) ($co['WorkflowIDReusePolicy'] ?? 0))
            /* Same bool wire form as activities (see scheduleActivity). */
            ->setCancellationType(
                ($co['WaitForCancellation'] ?? false)
                    ? CoresdkChildCancellationType::WAIT_CANCELLATION_COMPLETED
                    : CoresdkChildCancellationType::TRY_CANCEL,
            );

        if (($ns = (int) ($co['WorkflowExecutionTimeout'] ?? 0)) > 0) {
            $start->setWorkflowExecutionTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($co['WorkflowRunTimeout'] ?? 0)) > 0) {
            $start->setWorkflowRunTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($co['WorkflowTaskTimeout'] ?? 0)) > 0) {
            $start->setWorkflowTaskTimeout(self::nsToDuration($ns));
        }
        if (\is_string($co['CronSchedule'] ?? null) && $co['CronSchedule'] !== '') {
            $start->setCronSchedule($co['CronSchedule']);
        }
        if (\is_array($co['RetryPolicy'] ?? null)) {
            $start->setRetryPolicy(self::retryPolicy($co['RetryPolicy']));
        }

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $start->setHeaders($headers);
        }

        return (new WorkflowCommand())->setStartChildWorkflowExecution($start);
    }

    /**
     * Signal another workflow. The target is a oneof: a specific external
     * execution (namespace + workflow id + optional run id) or, when the SDK
     * flags childWorkflowOnly, just a child workflow id. seq is this run's
     * deterministic sequence number so the later resolve_signal_external_workflow
     * job maps back to the promise across replays. (Cancelling a still-pending
     * signal is not yet mapped — stageCancel raises for the 'signal-external'
     * kind rather than hang silently.)
     */
    private function signalExternalWorkflow(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $seq = $this->seqFor($command->getID(), 'signal-external');

        $command->getPayloads()->setDataConverter($this->dataConverter);

        $signal = (new SignalExternalWorkflowExecution())
            ->setSeq($seq)
            ->setSignalName((string) ($options['signal'] ?? ''))
            ->setArgs($command->getPayloads()->toPayloads()->getPayloads());

        if ($options['childWorkflowOnly'] ?? false) {
            $signal->setChildWorkflowId((string) ($options['workflowID'] ?? ''));
        } else {
            $signal->setWorkflowExecution(
                (new NamespacedWorkflowExecution())
                    ->setNamespace((string) ($options['namespace'] ?? 'default'))
                    ->setWorkflowId((string) ($options['workflowID'] ?? ''))
                    ->setRunId((string) ($options['runID'] ?? '')),
            );
        }

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $signal->setHeaders($headers);
        }

        return (new WorkflowCommand())->setSignalExternalWorkflowExecution($signal);
    }

    /**
     * Request cancellation of an external workflow. The target is always a
     * namespaced execution (no child-id shortcut, unlike signal). seq is this
     * run's deterministic sequence number so the later
     * resolve_request_cancel_external_workflow job maps back to the promise across
     * replays. (Cancelling this pending request is not mapped — stageCancel raises
     * for the 'cancel-external' kind rather than hang silently.)
     */
    private function cancelExternalWorkflow(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $seq = $this->seqFor($command->getID(), 'cancel-external');

        $cancel = (new RequestCancelExternalWorkflowExecution())
            ->setSeq($seq)
            ->setWorkflowExecution(
                (new NamespacedWorkflowExecution())
                    ->setNamespace((string) ($options['namespace'] ?? 'default'))
                    ->setWorkflowId((string) ($options['workflowID'] ?? ''))
                    ->setRunId((string) ($options['runID'] ?? '')),
            );

        return (new WorkflowCommand())->setRequestCancelExternalWorkflowExecution($cancel);
    }

    /**
     * Upsert (add or update) the workflow's search attributes. Fire-and-forget:
     * no seq and no resolution. Each value is encoded to a Payload exactly as the
     * client start path does (DataConverter::toPayload), into the SearchAttributes
     * indexed-fields map. The attribute names must already be registered on the
     * namespace.
     */
    private function upsertSearchAttributes(RequestInterface $command): WorkflowCommand
    {
        $attributes = (array) ($command->getOptions()['searchAttributes'] ?? []);

        $fields = [];
        foreach ($attributes as $key => $value) {
            $fields[$key] = $this->dataConverter->toPayload($value);
        }

        return (new WorkflowCommand())->setUpsertWorkflowSearchAttributes(
            (new UpsertWorkflowSearchAttributes())->setSearchAttributes(
                (new SearchAttributes())->setIndexedFields($fields),
            ),
        );
    }

    /**
     * Upsert typed search attributes — the strongly-typed sibling of
     * {@see upsertSearchAttributes}, riding the same coresdk command. The SDK
     * request marshals each update to ['type' => <ValueType::value>, 'operation'
     * => 'set'|'unset', 'value' => <scalar|list|RFC3339 datetime>]; we encode the
     * value to a Payload and tag it with the search-attribute `type` metadata the
     * server keys on (Bool/Int/Double/Keyword/KeywordList/Text/Datetime). An unset
     * removes the attribute, expressed (as the other SDKs do) by upserting a null
     * value carrying only the type tag. Fire-and-forget: no seq, no resolution.
     */
    private function upsertTypedSearchAttributes(RequestInterface $command): WorkflowCommand
    {
        $attributes = (array) ($command->getOptions()['search_attributes'] ?? []);

        $fields = [];
        foreach ($attributes as $name => $update) {
            $update = (array) $update;
            $unset = ($update['operation'] ?? 'set') === 'unset';

            $payload = $this->dataConverter->toPayload($unset ? null : ($update['value'] ?? null));

            $type = self::searchAttributeType((string) ($update['type'] ?? ''));
            if ($type !== '') {
                $payload->getMetadata()['type'] = $type;
            }

            $fields[(string) $name] = $payload;
        }

        return (new WorkflowCommand())->setUpsertWorkflowSearchAttributes(
            (new UpsertWorkflowSearchAttributes())->setSearchAttributes(
                (new SearchAttributes())->setIndexedFields($fields),
            ),
        );
    }

    /**
     * Map the SDK's ValueType (its enum *value*, e.g. 'int64') to the search-
     * attribute `type` metadata name the server tags payloads with (e.g. 'Int').
     * Unknown types yield '' so the payload is sent untagged (the server can still
     * resolve it from the registered attribute).
     */
    private static function searchAttributeType(string $valueType): string
    {
        return match ($valueType) {
            'bool' => 'Bool',
            'float64' => 'Double',
            'int64' => 'Int',
            'keyword' => 'Keyword',
            'keyword_list' => 'KeywordList',
            'string' => 'Text',
            'datetime' => 'Datetime',
            default => '',
        };
    }

    /**
     * Upsert (add, change or remove) the workflow's memo. Fire-and-forget: no seq
     * and no resolution. Each value is encoded to a Payload via the data converter
     * exactly as the client start path does (WorkflowOptions::toMemo), into the
     * Memo fields map wrapped in a ModifyWorkflowProperties command.
     */
    private function upsertMemo(RequestInterface $command): WorkflowCommand
    {
        $memo = (array) ($command->getOptions()['memo'] ?? []);

        $fields = [];
        foreach ($memo as $key => $value) {
            $fields[$key] = $this->dataConverter->toPayload($value);
        }

        return (new WorkflowCommand())->setModifyWorkflowProperties(
            (new ModifyWorkflowProperties())->setUpsertedMemo(
                (new Memo())->setFields($fields),
            ),
        );
    }

    /**
     * Continue this workflow as a new run. The handler returns the never-
     * resolving continueAsNew promise, so no CompleteWorkflow follows: this
     * command terminates the run by itself.
     */
    private function continueAsNew(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $co = $options['options'] ?? [];

        $command->getPayloads()->setDataConverter($this->dataConverter);

        $can = (new ContinueAsNewWorkflowExecution())
            ->setWorkflowType((string) ($options['name'] ?? ''))
            ->setTaskQueue((string) ($co['TaskQueueName'] ?? ''))
            ->setArguments($command->getPayloads()->toPayloads()->getPayloads());

        if (($ns = (int) ($co['WorkflowRunTimeout'] ?? 0)) > 0) {
            $can->setWorkflowRunTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($co['WorkflowTaskTimeout'] ?? 0)) > 0) {
            $can->setWorkflowTaskTimeout(self::nsToDuration($ns));
        }

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $can->setHeaders($headers);
        }

        return (new WorkflowCommand())->setContinueAsNewWorkflowExecution($can);
    }

    /** The command's header as a payload map, bound to our converter. */
    private function headerFields(RequestInterface $command): array
    {
        $header = $command->getHeader();
        $header->setDataConverter($this->dataConverter);

        $fields = [];
        foreach ($header->toHeader()->getFields() as $key => $payload) {
            $fields[$key] = $payload;
        }

        return $fields;
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
            /* An activity inherits the workflow's task queue when none is set. */
            ->setTaskQueue($taskQueue !== '' ? $taskQueue : $this->taskQueue)
            ->setArguments($command->getPayloads()->toPayloads()->getPayloads())
            ->setCancellationType(
                ($ao['WaitForCancellation'] ?? false)
                    ? CoresdkActivityCancellationType::WAIT_CANCELLATION_COMPLETED
                    : CoresdkActivityCancellationType::TRY_CANCEL,
            );

        self::applyActivityTimeouts($schedule, $ao);

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $schedule->setHeaders($headers);
        }

        if (\is_array($ao['RetryPolicy'] ?? null)) {
            $schedule->setRetryPolicy(self::retryPolicy($ao['RetryPolicy']));
        }

        return (new WorkflowCommand())->setScheduleActivity($schedule);
    }

    /**
     * Schedule a local activity — run by the worker in-process and recorded as a
     * marker rather than dispatched to the server, but otherwise driven like a
     * regular activity: the core delivers it through the same activity-task channel
     * (Start.is_local) and resolves it through the same resolve_activity job
     * (ResolveActivity.is_local), so only the encode is special. seq is this run's
     * deterministic sequence number; the resolution maps back to its promise across
     * replays (kind 'local-activity' so a Cancel routes to RequestCancelLocalActivity).
     * LocalActivityOptions marshals fewer fields than ActivityOptions: no task queue
     * (it never leaves the worker) and no activity id (defaults to the seq). attempt
     * is 1 for a fresh schedule; the core manages fast retries within
     * local_retry_threshold itself. (A retry whose backoff exceeds that threshold
     * resolves as DoBackoff — not yet handled; resolveActivity raises on it rather
     * than hang.)
     */
    private function scheduleLocalActivity(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $name = (string) ($options['name'] ?? '');
        $lo = $options['options'] ?? [];

        $command->getPayloads()->setDataConverter($this->dataConverter);
        $seq = $this->seqFor($command->getID(), 'local-activity');

        $schedule = (new ScheduleLocalActivity())
            ->setSeq($seq)
            ->setActivityId((string) $seq)
            ->setActivityType($name)
            ->setArguments($command->getPayloads()->toPayloads()->getPayloads())
            ->setAttempt(1);

        if (($ns = (int) ($lo['ScheduleToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setScheduleToCloseTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($lo['StartToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setStartToCloseTimeout(self::nsToDuration($ns));
        }

        if (\is_array($lo['RetryPolicy'] ?? null)) {
            $schedule->setRetryPolicy(self::retryPolicy($lo['RetryPolicy']));
        }

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $schedule->setHeaders($headers);
        }

        return (new WorkflowCommand())->setScheduleLocalActivity($schedule);
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
