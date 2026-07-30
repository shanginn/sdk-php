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
use Coresdk\Nexus\NexusOperationCancellationType as CoresdkNexusCancellationType;
use Coresdk\WorkflowActivation\RemoveFromCache\EvictionReason;
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
use Coresdk\WorkflowCommands\RequestCancelNexusOperation;
use Coresdk\WorkflowCommands\ScheduleActivity;
use Coresdk\WorkflowCommands\ScheduleLocalActivity;
use Coresdk\WorkflowCommands\ScheduleNexusOperation;
use Coresdk\WorkflowCommands\SetPatchMarker;
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
use Temporal\Api\Common\V1\Priority;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\Api\Common\V1\SearchAttributes;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Sdk\V1\UserMetadata;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\FailureConverter;
use Temporal\Interceptor\Header;
use Temporal\Internal\Workflow\NexusStartEnvelope;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Worker\Transport\Command\Client\UpdateResponse;
use Temporal\Worker\Transport\Command\CommandInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\Transport\Command\Server\TickInfo;
use Temporal\Worker\Transport\Command\ServerResponseInterface;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\ContinueAsNewSuggestedReason;
use Temporal\Workflow\WorkflowExecution;

/**
 * The coresdk workflow codec maps the SDK engine to Temporal Rust Core's
 * strongly typed protobuf.
 *
 * decode: a coresdk WorkflowActivation (a batch of jobs) becomes the SDK command
 * stream the engine already understands — a server request per job that drives
 * the workflow forward (initialize/signal/query/...), or a server response that
 * resolves a command the workflow previously issued (timer fired, activity
 * resolved, ...).
 *
 * encode: the SDK's outgoing command queue (CompleteWorkflow, NewTimer,
 * ExecuteActivity, ...) becomes a coresdk WorkflowActivationCompletion. Each
 * command maps to a specific typed coresdk WorkflowCommand.
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
 * local), side effects (persisted through a private local activity), signals,
 * queries, cancellation (of the workflow, its timers,
 * activities, child workflows and Nexus operations), child workflows, Nexus
 * operations, continue-as-new, signalling and cancelling external/child
 * workflows, updates (validate/accept/reject/complete), upserting search
 * attributes (untyped and typed) and memo, panic (a retryable workflow error
 * reported as a failed task), versioning via getVersion/patches, and surviving
 * a reset (the new random seed is consumed).
 * Jobs and commands that are not yet mapped raise so the gap is explicit rather
 * than a silently hung workflow.
 */
final class CoresdkWorkflowCodec implements CodecInterface
{
    private const GET_VERSION_PATCH_PREFIX = '__temporal_php_get_version:';

    private string $runId = '';
    private string $taskQueue = '';

    /**
     * Core eviction metadata observed while decoding activations.
     *
     * Live workers consume and discard this by default. Replay explicitly
     * captures it so a NONDETERMINISM eviction can become a failed replay after
     * the eviction activation has still been completed normally.
     *
     * @var list<array{runId: string, reason: int, reasonName: string, message: string}>
     */
    private array $evictions = [];

    /**
     * Per-run deterministic seq state, keyed by run id and surviving across the
     * run's activations. 'kind' ('timer' | 'activity' | 'child-workflow') is
     * what a later Cancel of the command needs to pick the matching coresdk
     * cancel command. 'wfId' is this run's workflow id (the deterministic
     * default for child workflow ids). 'children' tracks per-seq child state:
     * the child's workflow id and the pending GetChildWorkflowExecution
     * request id its start resolution must answer. 'updates' maps each update id
     * to its protocol_instance_id, which the coresdk UpdateResponse needs but the
     * SDK's UpdateResponse command does not carry. getVersion uses two patch sets:
     * 'patchesNotified' is change ids the core reported present in history
     * (notify_has_patch), which decides the returned version; 'patchesMarked' is
     * change ids for which a SetPatchMarker has been issued this run, so the marker
     * is emitted once (but re-emitted after an eviction, to match the history).
     *
     * @var array<string, array{
     *     next: int,
     *     idToSeq: array<int, int>,
     *     seqToId: array<int, int>,
     *     kind: array<int, string>,
     *     wfId: string,
     *     children: array<int, array{wfId: string, getId: int|null}>,
     *     nexus: array<int, array{getId: int|null}>,
     *     updates: array<string, string>,
     *     patchesNotified: array<string, true>,
     *     patchesMarked: array<string, true>,
     *     versions: array<string, int>,
     *     versioningBehavior: int,
     *     localActivities: array<int, array{id: int, schedule: string}>,
     *     localBackoffTimers: array<int, array{
     *         id: int,
     *         schedule: string,
     *         attempt: int,
     *         originalScheduleTime: string,
     *     }>,
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
     * queued before it. Captured here and turned into the failed completion by
     * {@see encodeStaged}.
     */
    private ?\Throwable $panic = null;

    /**
     * Whether the current activation is a replay (carried from decode to the
     * stage/encode pass). getVersion needs it: on replay a change id absent from
     * history was not part of the original run and must resolve to the old branch,
     * whereas on a live task an unseen change id is a new patch to record.
     */
    private bool $isReplaying = false;

    /**
     * getVersion resolutions captured during the current activation: each maps the
     * SDK request id to the version the workflow must observe. getVersion is a
     * request/response like a query — the codec resolves it locally (no server
     * round-trip) and the factory dispatches the value back off this list, the way
     * it synthesizes cancelled-timer rejections.
     *
     * @var list<array{id: int, version: int}>
     */
    private array $versionResolutions = [];

    /**
     * @param null|callable(string, string): int $workflowVersioningBehavior
     */
    public function __construct(
        private readonly DataConverterInterface $dataConverter,
        private readonly mixed $workflowVersioningBehavior = null,
    ) {}

    public function decode(string $batch, array $headers = []): iterable
    {
        $activation = new WorkflowActivation();
        $activation->mergeFromString($batch);

        $this->runId = $activation->getRunId();
        $this->taskQueue = $taskQueue = (string) ($headers['taskQueue'] ?? '');
        $namespace = (string) ($headers['namespace'] ?? 'default');
        $this->isReplaying = $activation->getIsReplaying();

        $timestamp = $activation->getTimestamp();
        $continueAsNewSuggestedReasons = [];
        foreach ($activation->getSuggestContinueAsNewReasons() as $reason) {
            $reason = ContinueAsNewSuggestedReason::tryFrom($reason);
            /** @psalm-suppress TypeDoesNotContainType Future Core versions may send an enum value unknown to this SDK. */
            if ($reason !== null) {
                $continueAsNewSuggestedReasons[] = $reason;
            }
        }
        $tick = new TickInfo(
            time: $timestamp !== null ? $timestamp->toDateTime() : new \DateTimeImmutable(),
            historyLength: $activation->getHistoryLength(),
            historySize: (int) $activation->getHistorySizeBytes(),
            continueAsNewSuggested: $activation->getContinueAsNewSuggested(),
            continueAsNewSuggestedReasons: $continueAsNewSuggestedReasons,
            targetWorkerDeploymentVersionChanged: $activation->getTargetWorkerDeploymentVersionChanged(),
            isReplaying: $activation->getIsReplaying(),
        );

        $emitted = false;
        foreach ($activation->getJobs() as $job) {
            foreach ($this->decodeJob($job, $tick, $taskQueue, $namespace) as $command) {
                $emitted = true;
                yield $command;
            }
        }

        // Some Core jobs only mutate codec state (for example notify_has_patch
        // and update_random_seed) and therefore produce no SDK command. Still
        // deliver the activation metadata before the Workflow is ticked.
        if (!$emitted && $this->runId !== '' && isset($this->runs[$this->runId])) {
            yield new ServerRequest(
                name: 'UpdateWorkflowInfo',
                info: $tick,
                id: $this->runId,
            );
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

        /* getVersion maps onto the coresdk patch mechanism: it may stage a
           SetPatchMarker, and always resolves the request locally with a version.
           Handled apart from encodeCommand, which assumes one command per request. */
        if ($command->getName() === 'GetVersion') {
            $this->stageGetVersion($command);
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

        /* Like a child workflow, a Nexus operation has a separate start
           handshake and result promise. Associate the handshake request with
           the ScheduleNexusOperation seq so ResolveNexusOperationStart can
           answer the right promise without putting a second command on the
           core wire. */
        if ($command->getName() === 'GetNexusOperationStarted') {
            $executeId = (int) ($command->getOptions()['id'] ?? 0);
            $run = &$this->run();
            $seq = $run['idToSeq'][$executeId]
                ?? throw new \RuntimeException("GetNexusOperationStarted for unknown command id {$executeId}");
            if (($run['kind'][$seq] ?? '') !== 'nexus' || !isset($run['nexus'][$seq])) {
                throw new \RuntimeException(
                    "GetNexusOperationStarted command id {$executeId} is not a Nexus operation",
                );
            }
            $run['nexus'][$seq]['getId'] = $command->getID();
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

    /**
     * Build the completion from the staged commands and query results.
     */
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

        $successful = (new Success())->setCommands($wfCommands);
        $versioningBehavior = $this->run()['versioningBehavior'];
        if ($versioningBehavior !== 0) {
            $successful->setVersioningBehavior($versioningBehavior);
        }

        $completion = (new WorkflowActivationCompletion())
            ->setRunId($this->runId)
            ->setSuccessful($successful);

        return $completion->serializeToString();
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
        $this->versionResolutions = [];

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
     * @return list<array{runId: string, reason: int, reasonName: string, message: string}>
     */
    public function drainEvictions(): array
    {
        $evictions = $this->evictions;
        $this->evictions = [];

        return $evictions;
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
     * Drain the getVersion resolutions captured this activation. The factory
     * dispatches each as a SuccessResponse so the awaiting getVersion promise
     * resolves and the workflow advances within the same activation.
     *
     * @return list<array{id: int, version: int}>
     */
    public function drainVersionResolutions(): array
    {
        $resolutions = $this->versionResolutions;
        $this->versionResolutions = [];

        return $resolutions;
    }

    /**
     * Record a resolved query; emitted as a QueryResult command by encode().
     */
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

    /**
     * Record a failed query (unknown type, handler threw); see encode().
     */
    public function recordQueryFailure(string $queryId, \Throwable $error): void
    {
        $this->queryResults[] = (new QueryResult())
            ->setQueryId($queryId)
            ->setFailed(FailureConverter::mapExceptionToFailure($error, $this->dataConverter));
    }

    /**
     * @return array<string, mixed>
     */
    private static function protobufJson(object $message): array
    {
        return \json_decode(
            $message->serializeToJsonString(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function retryPolicyInfo(RetryPolicy $policy): array
    {
        return [
            'initial_interval' => self::durationParts($policy->getInitialInterval()),
            'backoff_coefficient' => $policy->getBackoffCoefficient(),
            'maximum_interval' => self::durationParts($policy->getMaximumInterval()),
            'maximum_attempts' => $policy->getMaximumAttempts(),
            'non_retryable_error_types' => \iterator_to_array($policy->getNonRetryableErrorTypes()),
        ];
    }

    /**
     * @return array{seconds: int|string, nanos: int}
     */
    private static function durationParts(?Duration $duration): array
    {
        return [
            'seconds' => $duration?->getSeconds() ?? 0,
            'nanos' => $duration?->getNanos() ?? 0,
        ];
    }

    private static function durationNanoseconds(?Duration $duration): int
    {
        return $duration === null
            ? 0
            : ((int) $duration->getSeconds() * 1_000_000_000) + $duration->getNanos();
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

    private static function priority(array $value): Priority
    {
        return (new Priority())
            ->setPriorityKey((int) ($value['PriorityKey'] ?? $value['priority_key'] ?? 0))
            ->setFairnessKey((string) ($value['FairnessKey'] ?? $value['fairness_key'] ?? ''))
            ->setFairnessWeight((float) ($value['FairnessWeight'] ?? $value['fairness_weight'] ?? 0.0));
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

    private static function versionPatchId(string $changeId, int $version): string
    {
        return self::GET_VERSION_PATCH_PREFIX . \rawurlencode($changeId) . ':' . $version;
    }

    /**
     * @return array{changeId: string, version: int}|null
     */
    private static function versionFromPatchId(string $patchId): ?array
    {
        if (!\str_starts_with($patchId, self::GET_VERSION_PATCH_PREFIX)) {
            return null;
        }

        $encoded = \substr($patchId, \strlen(self::GET_VERSION_PATCH_PREFIX));
        $separator = \strrpos($encoded, ':');
        if ($separator === false) {
            return null;
        }

        $version = \substr($encoded, $separator + 1);
        if (\preg_match('/^-?\d+$/D', $version) !== 1) {
            return null;
        }

        return [
            'changeId' => \rawurldecode(\substr($encoded, 0, $separator)),
            'version' => (int) $version,
        ];
    }

    private static function nexusStartEnvelope(bool $async, string $token): NexusStartEnvelope
    {
        $envelope = new NexusStartEnvelope();
        $envelope->async = $async;
        $envelope->token = $token;

        return $envelope;
    }

    /**
     * SDK and Core use different numeric values for WaitCompleted: SDK follows
     * sdk-go (4), while Core makes it the protobuf default (0).
     */
    private static function nexusCancellationType(int $type): int
    {
        return match ($type) {
            NexusOperationCancellationType::Unspecified->value,
            NexusOperationCancellationType::WaitCompleted->value =>
                CoresdkNexusCancellationType::WAIT_CANCELLATION_COMPLETED,
            NexusOperationCancellationType::Abandon->value =>
                CoresdkNexusCancellationType::ABANDON,
            NexusOperationCancellationType::TryCancel->value =>
                CoresdkNexusCancellationType::TRY_CANCEL,
            NexusOperationCancellationType::WaitRequested->value =>
                CoresdkNexusCancellationType::WAIT_CANCELLATION_REQUESTED,
            default => throw new \RuntimeException(
                "unsupported Nexus operation cancellation type {$type}",
            ),
        };
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
            } elseif ($kind === 'nexus') {
                /* Core applies the cancellation type carried by the schedule and
                   resolves the operation promise when that policy permits. */
                $this->staged[] = (new WorkflowCommand())->setRequestCancelNexusOperation(
                    (new RequestCancelNexusOperation())->setSeq($seq),
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
     * One job usually yields one engine command, but a few yield more (a failed
     * child start must reject both the start waiter and the result promise — the
     * core sends nothing further for that seq), hence the list.
     *
     * @return list<CommandInterface>
     */
    private function decodeJob(
        WorkflowActivationJob $job,
        TickInfo $tick,
        string $taskQueue,
        string $namespace,
    ): array {
        $variant = $job->getVariant();

        return match ($variant) {
            'initialize_workflow' => [$this->startWorkflow($job, $tick, $taskQueue, $namespace)],
            'signal_workflow' => [$this->signalWorkflow($job, $tick)],
            'query_workflow' => [$this->queryWorkflow($job, $tick)],
            'cancel_workflow' => [$this->cancelWorkflow($tick)],
            'fire_timer' => $this->fireTimer($job, $tick),
            'resolve_activity' => $this->resolveActivity($job, $tick),
            'resolve_child_workflow_execution_start' => $this->resolveChildStart($job, $tick),
            'resolve_child_workflow_execution' => [$this->resolveChild($job, $tick)],
            'resolve_nexus_operation_start' => $this->resolveNexusStart($job, $tick),
            'resolve_nexus_operation' => [$this->resolveNexus($job, $tick)],
            'resolve_signal_external_workflow' => [$this->resolveSignalExternal($job, $tick)],
            'resolve_request_cancel_external_workflow' => [$this->resolveCancelExternal($job, $tick)],
            'do_update' => [$this->doUpdate($job, $tick)],
            'notify_has_patch' => $this->notifyHasPatch($job),
            'update_random_seed' => $this->updateRandomSeed(),
            'remove_from_cache' => [$this->removeFromCache($job, $tick)],
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
    private function removeFromCache(WorkflowActivationJob $job, TickInfo $tick): ServerRequest
    {
        $eviction = $job->getRemoveFromCache();
        $reason = $eviction?->getReason() ?? EvictionReason::UNSPECIFIED;
        try {
            $reasonName = EvictionReason::name($reason);
        } catch (\UnexpectedValueException) {
            $reasonName = 'UNKNOWN';
        }
        $this->evictions[] = [
            'runId' => $this->runId,
            'reason' => $reason,
            'reasonName' => $reasonName,
            'message' => $eviction?->getMessage() ?? '',
        ];

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
    private function fireTimer(WorkflowActivationJob $job, TickInfo $tick): array
    {
        $seq = $job->getFireTimer()->getSeq();
        $run = &$this->run();
        $backoff = $run['localBackoffTimers'][$seq] ?? null;

        if ($backoff === null) {
            return [new SuccessResponse(
                values: null,
                id: $this->idForSeq($seq),
                info: $tick,
            )];
        }

        unset($run['localBackoffTimers'][$seq]);

        $retrySeq = $this->remapSeqFor($backoff['id'], 'local-activity');
        $schedule = new ScheduleLocalActivity();
        $schedule->mergeFromString($backoff['schedule']);
        $schedule
            ->setSeq($retrySeq)
            ->setAttempt($backoff['attempt']);

        $originalScheduleTime = new \Google\Protobuf\Timestamp();
        $originalScheduleTime->mergeFromString($backoff['originalScheduleTime']);
        $schedule->setOriginalScheduleTime($originalScheduleTime);

        $serialized = $schedule->serializeToString();
        $run['localActivities'][$retrySeq] = [
            'id' => $backoff['id'],
            'schedule' => $serialized,
        ];
        $this->staged[] = (new WorkflowCommand())->setScheduleLocalActivity($schedule);

        /* The timer only drives the retry state machine inside this codec. The
           workflow's original local-activity promise must stay pending until
           the retried local activity itself resolves. */
        return [];
    }

    /**
     * Resolve an activity the workflow scheduled. The resolution is an oneof: a
     * success carries result payloads, a failure or cancellation carries a
     * Temporal failure the engine rejects the promise with. The job's seq maps
     * back to the current run's ScheduleActivity command id.
     */
    private function resolveActivity(WorkflowActivationJob $job, TickInfo $tick): array
    {
        $resolve = $job->getResolveActivity();
        $seq = $resolve->getSeq();
        $id = $this->idForSeq($seq);
        $resolution = $resolve->getResult();

        if ($resolution->getStatus() === 'backoff') {
            $this->stageLocalActivityBackoff($seq, $id, $resolution->getBackoff());

            return [];
        }

        unset($this->run()['localActivities'][$seq]);

        return [match ($resolution->getStatus()) {
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
        }];
    }

    /**
     * A local retry whose delay exceeds Core's local retry threshold is handed
     * back to the language as DoBackoff. Keep the workflow's existing promise
     * pending, schedule a deterministic workflow timer, then have fireTimer()
     * issue the same local activity again with Core's attempt and original
     * schedule time. This is the same two-stage state machine used by the Rust
     * SDK; none of the delay occurs on the real TrueAsync reactor.
     */
    private function stageLocalActivityBackoff(
        int $activitySeq,
        int $commandId,
        \Coresdk\ActivityResult\DoBackoff $backoff,
    ): void {
        $run = &$this->run();
        $activity = $run['localActivities'][$activitySeq] ?? null;

        if ($activity === null) {
            throw new \RuntimeException(
                "DoBackoff received for non-local or unknown activity seq {$activitySeq}",
            );
        }

        $duration = $backoff->getBackoffDuration()
            ?? throw new \RuntimeException("DoBackoff for seq {$activitySeq} has no duration");
        $originalScheduleTime = $backoff->getOriginalScheduleTime()
            ?? throw new \RuntimeException("DoBackoff for seq {$activitySeq} has no original schedule time");

        unset($run['localActivities'][$activitySeq]);

        $timerSeq = $this->remapSeqFor($commandId, 'timer');
        $run['localBackoffTimers'][$timerSeq] = [
            'id' => $commandId,
            'schedule' => $activity['schedule'],
            'attempt' => $backoff->getAttempt(),
            'originalScheduleTime' => $originalScheduleTime->serializeToString(),
        ];

        $this->staged[] = (new WorkflowCommand())->setStartTimer(
            (new StartTimer())
                ->setSeq($timerSeq)
                ->setStartToFireTimeout($duration),
        );
    }

    /**
     * A single optional result payload as decoded values, or null when absent.
     */
    private function valuesFromPayload(?Payload $payload): ?ValuesInterface
    {
        return $payload !== null
            ? EncodedValues::fromPayloads((new Payloads())->setPayloads([$payload]), $this->dataConverter)
            : null;
    }

    private function activityCompleted(ActivitySuccess $success, int $id, TickInfo $tick): SuccessResponse
    {
        return new SuccessResponse(
            values: $this->valuesFromPayload($success->getResult()),
            id: $id,
            info: $tick,
        );
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
                return [new SuccessResponse(
                    /*
                     * Values created with fromValues() are already decoded, so
                     * getValue(..., WorkflowExecution::class) returns the value
                     * verbatim. Return the DTO itself; returning its marshalled
                     * array makes the typed child stub reject its deferred
                     * signal callback with a TypeError.
                     */
                    values: EncodedValues::fromValues([new WorkflowExecution(
                        $child['wfId'] ?? '',
                        $resolve->getSucceeded()->getRunId(),
                    )], $this->dataConverter),
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

    /**
     * The child workflow itself resolved: answer the ExecuteChildWorkflow promise.
     */
    private function resolveChild(WorkflowActivationJob $job, TickInfo $tick): ServerResponseInterface
    {
        $resolve = $job->getResolveChildWorkflowExecution();
        $id = $this->idForSeq($resolve->getSeq());
        $result = $resolve->getResult();

        switch ($result->getStatus()) {
            case 'completed':
                return new SuccessResponse(
                    values: $this->valuesFromPayload($result->getCompleted()->getResult()),
                    id: $id,
                    info: $tick,
                );

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
     * Resolve the Nexus start handshake. An asynchronous start carries its
     * operation token; a synchronous start is acknowledged with an empty token
     * and is followed by ResolveNexusOperation in the same activation. A failed
     * start is terminal, so both the start waiter and the result promise must be
     * rejected because Core will not send a later result job.
     *
     * @return list<CommandInterface>
     */
    private function resolveNexusStart(WorkflowActivationJob $job, TickInfo $tick): array
    {
        $resolve = $job->getResolveNexusOperationStart()
            ?? throw new \RuntimeException('Nexus start resolution job has no resolution');
        $seq = $resolve->getSeq();
        $executeId = $this->idForSeq($seq);

        $run = &$this->run();
        $getId = $run['nexus'][$seq]['getId'] ?? null;
        if ($getId === null) {
            throw new \RuntimeException("no start waiter registered for Nexus operation seq {$seq}");
        }
        $run['nexus'][$seq]['getId'] = null;

        switch ($resolve->getStatus()) {
            case 'operation_token':
                return [new SuccessResponse(
                    values: EncodedValues::fromValues([
                        self::nexusStartEnvelope(true, $resolve->getOperationToken()),
                    ], $this->dataConverter),
                    id: $getId,
                    info: $tick,
                )];

            case 'started_sync':
                return [new SuccessResponse(
                    values: EncodedValues::fromValues([
                        self::nexusStartEnvelope(false, ''),
                    ], $this->dataConverter),
                    id: $getId,
                    info: $tick,
                )];

            case 'failed':
                $protoFailure = $resolve->getFailed()
                    ?? throw new \RuntimeException(
                        "Nexus operation start seq {$seq} failed without a failure",
                    );
                $failure = FailureConverter::mapFailureToException(
                    $protoFailure,
                    $this->dataConverter,
                );
                unset($run['nexus'][$seq]);

                return [
                    new FailureResponse(failure: $failure, id: $getId, info: $tick),
                    new FailureResponse(failure: $failure, id: $executeId, info: $tick),
                ];
        }

        throw new \RuntimeException(
            "coresdk Nexus operation start resolution not supported: {$resolve->getStatus()}",
        );
    }

    /**
     * Resolve the result promise for a Nexus operation that started
     * asynchronously or synchronously.
     */
    private function resolveNexus(
        WorkflowActivationJob $job,
        TickInfo $tick,
    ): ServerResponseInterface {
        $resolve = $job->getResolveNexusOperation()
            ?? throw new \RuntimeException('Nexus result job has no resolution');
        $seq = $resolve->getSeq();
        $id = $this->idForSeq($seq);
        $result = $resolve->getResult()
            ?? throw new \RuntimeException("Nexus operation seq {$seq} resolved without a result");

        $response = match ($result->getStatus()) {
            'completed' => new SuccessResponse(
                values: $this->valuesFromPayload($result->getCompleted()),
                id: $id,
                info: $tick,
            ),
            'failed' => $this->nexusFailureResponse(
                $result->getFailed(),
                $id,
                $tick,
                $seq,
                'failed',
            ),
            'cancelled' => $this->nexusFailureResponse(
                $result->getCancelled(),
                $id,
                $tick,
                $seq,
                'cancelled',
            ),
            'timed_out' => $this->nexusFailureResponse(
                $result->getTimedOut(),
                $id,
                $tick,
                $seq,
                'timed_out',
            ),
            default => throw new \RuntimeException(
                "coresdk Nexus operation resolution not supported: {$result->getStatus()}",
            ),
        };

        unset($this->run()['nexus'][$seq]);

        return $response;
    }

    private function nexusFailureResponse(
        ?Failure $failure,
        int $id,
        TickInfo $tick,
        int $seq,
        string $status,
    ): FailureResponse {
        if ($failure === null) {
            throw new \RuntimeException(
                "Nexus operation seq {$seq} resolved {$status} without a failure",
            );
        }

        return new FailureResponse(
            failure: FailureConverter::mapFailureToException(
                $failure,
                $this->dataConverter,
            ),
            id: $id,
            info: $tick,
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
            header: Header::fromPayloadCollection($update->getHeaders(), $this->dataConverter),
        );
    }

    /**
     * Resolve getVersion through Core's boolean patch primitive while retaining
     * PHP's integer version semantics. The marker id embeds both change id and
     * the version selected on the first live execution. On replay Core reports
     * that exact id through notify_has_patch, so raising maxSupported later does
     * not silently move existing executions to a newer branch.
     */
    private function stageGetVersion(RequestInterface $command): void
    {
        $options = $command->getOptions();
        $changeId = (string) ($options['changeID'] ?? '');
        $minSupported = (int) ($options['minSupported'] ?? 0);
        $maxSupported = (int) ($options['maxSupported'] ?? 0);

        $run = &$this->run();
        $version = $run['versions'][$changeId] ?? null;
        $markerId = null;

        if ($version === null) {
            foreach ($run['patchesNotified'] as $notifiedId => $_) {
                $recorded = self::versionFromPatchId($notifiedId);
                if ($recorded === null || $recorded['changeId'] !== $changeId) {
                    continue;
                }
                if ($version !== null && $version !== $recorded['version']) {
                    throw new \RuntimeException("Conflicting versions recorded for change {$changeId}.");
                }
                $version = $recorded['version'];
                $markerId = $notifiedId;
            }

            if ($version === null) {
                $version = $this->isReplaying ? $minSupported : $maxSupported;
                if (!$this->isReplaying) {
                    $markerId = self::versionPatchId($changeId, $version);
                }
            }

            $run['versions'][$changeId] = $version;
        } else {
            $candidate = self::versionPatchId($changeId, $version);
            if (isset($run['patchesNotified'][$candidate]) || !$this->isReplaying) {
                $markerId = $candidate;
            }
        }

        if ($version < $minSupported || $version > $maxSupported) {
            throw new \RuntimeException(\sprintf(
                'Recorded version %d for change %s is outside the supported range [%d, %d].',
                $version,
                $changeId,
                $minSupported,
                $maxSupported,
            ));
        }

        if ($markerId !== null && !isset($run['patchesMarked'][$markerId])) {
            $this->staged[] = (new WorkflowCommand())->setSetPatchMarker(
                (new SetPatchMarker())->setPatchId($markerId),
            );
            $run['patchesMarked'][$markerId] = true;
        }

        $this->versionResolutions[] = [
            'id' => $command->getID(),
            'version' => $version,
        ];
    }

    /**
     * The core detected a patch marker in history and is telling us the change
     * exists, pre-emptively (no command of ours prompted it). Record it so a later
     * getVersion for this change id resolves to the patched branch. Drives nothing
     * in the engine, so it yields no command.
     *
     * @return list<CommandInterface>
     */
    private function notifyHasPatch(WorkflowActivationJob $job): array
    {
        $this->run()['patchesNotified'][$job->getNotifyHasPatch()->getPatchId()] = true;

        return [];
    }

    /**
     * The core handed us a new random seed, which it does when a workflow is reset
     * (the reset run must draw different randomness than the original). The reused
     * engine derives workflow randomness through side effects, not a core-seeded
     * PRNG (there is nowhere to apply the seed), so this is a no-op — but it must
     * be consumed rather than raised, or a reset would fail every task. Drives
     * nothing in the engine, so it yields no command.
     *
     * @return list<CommandInterface>
     */
    private function updateRandomSeed(): array
    {
        return [];
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

    /**
     * Assign a fresh seq to an existing SDK command id. Local activity timer
     * backoff is one logical SDK promise but multiple Core operations (activity,
     * timer, retry activity), so cancellation must always follow its currently
     * active operation.
     */
    private function remapSeqFor(int $commandId, string $kind): int
    {
        $run = &$this->run();
        $seq = $run['next']++;
        $run['idToSeq'][$commandId] = $seq;
        $run['seqToId'][$seq] = $commandId;
        $run['kind'][$seq] = $kind;

        return $seq;
    }

    /**
     * The current run's seq state, created on first touch.
     */
    private function &run(): array
    {
        $run = &$this->runs[$this->runId];
        $run ??= [
            'next' => 1,
            'idToSeq' => [],
            'seqToId' => [],
            'kind' => [],
            'wfId' => '',
            'children' => [],
            'nexus' => [],
            'updates' => [],
            'patchesNotified' => [],
            'patchesMarked' => [],
            'versions' => [],
            'versioningBehavior' => 0,
            'localActivities' => [],
            'localBackoffTimers' => [],
        ];

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
        // Core marks a query activation as replaying while it restores workflow
        // state, but the query handler itself is a live read. The legacy host
        // exposed that handler with replay=false, which is also what prevents
        // its explicit logs from being suppressed.
        $queryTick = new TickInfo(
            time: $tick->time,
            historyLength: $tick->historyLength,
            historySize: $tick->historySize,
            continueAsNewSuggested: $tick->continueAsNewSuggested,
            continueAsNewSuggestedReasons: $tick->continueAsNewSuggestedReasons,
            targetWorkerDeploymentVersionChanged: $tick->targetWorkerDeploymentVersionChanged,
            isReplaying: false,
        );

        $payloads = new Payloads();
        $payloads->setPayloads(\iterator_to_array($query->getArguments()));

        return (new QueryServerRequest(
            queryId: $query->getQueryId(),
            name: 'InvokeQuery',
            info: $queryTick,
            options: ['name' => $query->getQueryType()],
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
            id: $this->runId,
        ))->withHeader(Header::fromPayloadCollection($query->getHeaders(), $this->dataConverter));
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
            header: Header::fromPayloadCollection($signal->getHeaders(), $this->dataConverter),
        );
    }

    private function startWorkflow(
        WorkflowActivationJob $job,
        TickInfo $tick,
        string $taskQueue,
        string $namespace,
    ): ServerRequest {
        $init = $job->getInitializeWorkflow();

        /* Remembered as the base of deterministic child workflow ids. */
        $run = &$this->run();
        $run['wfId'] = $init->getWorkflowId();
        $run['versioningBehavior'] = $this->workflowVersioningBehavior === null
            ? 0
            : ($this->workflowVersioningBehavior)($taskQueue, $init->getWorkflowType());

        $arguments = \iterator_to_array($init->getArguments());
        $lastCompletion = $init->hasLastCompletionResult()
            ? \iterator_to_array($init->getLastCompletionResult()->getPayloads())
            : [];

        $payloads = new Payloads();
        $payloads->setPayloads([...$arguments, ...$lastCompletion]);

        $parent = $init->hasParentWorkflowInfo()
            ? $init->getParentWorkflowInfo()
            : null;
        $root = $init->hasRootWorkflow()
            ? $init->getRootWorkflow()
            : null;
        $searchAttributes = $init->hasSearchAttributes()
            ? $init->getSearchAttributes()
            : null;
        $retryPolicy = $init->hasRetryPolicy()
            ? $init->getRetryPolicy()
            : null;
        $priority = $init->hasPriority()
            ? $init->getPriority()
            : null;

        $info = [
            'WorkflowType' => ['Name' => $init->getWorkflowType()],
            'WorkflowExecution' => ['ID' => $init->getWorkflowId(), 'RunID' => $this->runId],
            'TaskQueueName' => $taskQueue,
            'WorkflowExecutionTimeout' => self::durationNanoseconds($init->getWorkflowExecutionTimeout()),
            'WorkflowRunTimeout' => self::durationNanoseconds($init->getWorkflowRunTimeout()),
            'WorkflowTaskTimeout' => self::durationNanoseconds($init->getWorkflowTaskTimeout()),
            'Namespace' => $namespace,
            'Attempt' => $init->getAttempt(),
            'CronSchedule' => $init->getCronSchedule(),
            'ContinuedExecutionRunID' => $init->getContinuedFromExecutionRunId(),
            'FirstRunID' => $init->getFirstExecutionRunId(),
            // The original execution is the current run. It differs from the
            // first run after continue-as-new and equals it for an initial run.
            'OriginalRunID' => $this->runId,
            'ParentWorkflowNamespace' => $parent?->getNamespace(),
            'RootWorkflowExecution' => $root === null
                ? null
                : ['ID' => $root->getWorkflowId(), 'RunID' => $root->getRunId()],
            'ParentWorkflowExecution' => $parent === null
                ? null
                : ['ID' => $parent->getWorkflowId(), 'RunID' => $parent->getRunId()],
            'SearchAttributes' => $searchAttributes === null
                ? null
                : self::protobufJson($searchAttributes),
            'Memo' => $init->hasMemo()
                ? self::protobufJson($init->getMemo())
                : null,
            'BinaryChecksum' => '',
        ];

        if ($priority !== null) {
            $info['Priority'] = [
                'PriorityKey' => $priority->getPriorityKey(),
                'FairnessKey' => $priority->getFairnessKey(),
                // The bridge protobuf stores this as float32. Normalize its
                // binary round-off back to the user-provided decimal value.
                'FairnessWeight' => \round($priority->getFairnessWeight(), 6),
            ];
        }

        if ($retryPolicy !== null) {
            $info['RetryPolicy'] = self::retryPolicyInfo($retryPolicy);
        }

        $options = [
            'info' => $info,
            'lastCompletion' => \count($lastCompletion),
            'search_attributes' => $searchAttributes === null
                ? null
                : $this->typedSearchAttributes($searchAttributes),
        ];

        return new ServerRequest(
            name: 'StartWorkflow',
            info: $tick,
            options: $options,
            payloads: EncodedValues::fromPayloads($payloads, $this->dataConverter),
            id: $this->runId,
            header: Header::fromPayloadCollection($init->getHeaders(), $this->dataConverter),
        );
    }

    /**
     * @return array<string, array{type: string, value: mixed}>
     */
    private function typedSearchAttributes(SearchAttributes $attributes): array
    {
        $result = [];

        foreach ($attributes->getIndexedFields() as $name => $payload) {
            $metadata = $payload->getMetadata();
            if (!isset($metadata['type']) || $metadata['type'] === '') {
                continue;
            }

            $result[$name] = [
                'type' => $metadata['type'],
                'value' => $this->dataConverter->fromPayload($payload, null),
            ];
        }

        return $result;
    }

    private function encodeCommand(RequestInterface $command): WorkflowCommand
    {
        return match ($command->getName()) {
            'CompleteWorkflow' => $this->completeOrFail($command),
            'NewTimer' => $this->startTimer($command),
            'ExecuteActivity' => $this->scheduleActivity($command),
            'ExecuteLocalActivity' => $this->scheduleLocalActivity($command),
            'SideEffect' => $this->scheduleSideEffect($command),
            'ExecuteChildWorkflow' => $this->startChildWorkflow($command),
            'ExecuteNexusOperation' => $this->scheduleNexusOperation($command),
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
        if (\is_array($co['Priority'] ?? null)) {
            $start->setPriority(self::priority($co['Priority']));
        }
        $searchAttributes = $co['SearchAttributes'] ?? null;
        if (\is_array($searchAttributes) || \is_object($searchAttributes)) {
            $fields = [];
            foreach ((array) $searchAttributes as $name => $value) {
                $fields[(string) $name] = $this->dataConverter->toPayload($value);
            }

            $start->setSearchAttributes(
                (new SearchAttributes())->setIndexedFields($fields),
            );
        }

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $start->setHeaders($headers);
        }

        return $this->withUserMetadata(
            (new WorkflowCommand())->setStartChildWorkflowExecution($start),
            (string) ($co['StaticSummary'] ?? ''),
            (string) ($co['StaticDetails'] ?? ''),
        );
    }

    /**
     * Schedule a Nexus operation. Its seq is deterministic across replay and
     * correlates both the separate start handshake and the eventual result.
     * Nexus headers are raw strings and intentionally distinct from the
     * payload-typed Temporal interceptor header on the SDK request.
     */
    private function scheduleNexusOperation(RequestInterface $command): WorkflowCommand
    {
        $options = $command->getOptions();
        $no = (array) ($options['options'] ?? []);
        $seq = $this->seqFor($command->getID(), 'nexus');
        $run = &$this->run();
        $run['nexus'][$seq] = ['getId' => null];

        $schedule = (new ScheduleNexusOperation())
            ->setSeq($seq)
            ->setEndpoint((string) ($options['endpoint'] ?? ''))
            ->setService((string) ($options['service'] ?? ''))
            ->setOperation((string) ($options['operation'] ?? ''))
            ->setCancellationType(self::nexusCancellationType(
                (int) ($no['cancellationType'] ?? $no['CancellationType'] ?? 0),
            ));

        $command->getPayloads()->setDataConverter($this->dataConverter);
        $payloads = $command->getPayloads()->toPayloads()->getPayloads();
        if (\count($payloads) > 0) {
            $schedule->setInput($payloads[0]);
        }

        $headers = [];
        foreach ((array) ($options['nexusHeaders'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        if ($headers !== []) {
            $schedule->setNexusHeader($headers);
        }

        if (($ns = (int) ($no['scheduleToCloseTimeout'] ?? $no['ScheduleToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setScheduleToCloseTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($no['scheduleToStartTimeout'] ?? $no['ScheduleToStartTimeout'] ?? 0)) > 0) {
            $schedule->setScheduleToStartTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($no['startToCloseTimeout'] ?? $no['StartToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setStartToCloseTimeout(self::nsToDuration($ns));
        }

        return $this->withUserMetadata(
            (new WorkflowCommand())->setScheduleNexusOperation($schedule),
            (string) ($no['summary'] ?? $no['Summary'] ?? ''),
        );
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
            ->setArguments($command->getPayloads()->toPayloads()->getPayloads())
            ->setInitialVersioningBehavior((int) ($co['InitialVersioningBehavior'] ?? 0));

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

    /**
     * The command's header as a payload map, bound to our converter.
     */
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

        return $this->withUserMetadata((new WorkflowCommand())->setStartTimer(
            (new StartTimer())
                ->setSeq($this->seqFor($command->getID(), 'timer'))
                ->setStartToFireTimeout(self::msToDuration($ms)),
        ), (string) ($command->getOptions()['summary'] ?? ''));
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
        if (\is_array($ao['Priority'] ?? null)) {
            $schedule->setPriority(self::priority($ao['Priority']));
        }

        return $this->withUserMetadata(
            (new WorkflowCommand())->setScheduleActivity($schedule),
            (string) ($ao['Summary'] ?? ''),
        );
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
     * local_retry_threshold itself. A retry whose backoff exceeds that threshold
     * resolves as DoBackoff; the codec switches it to a deterministic workflow
     * timer and reschedules the same logical local activity when that timer fires.
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
            ->setAttempt(1)
            ->setCancellationType(CoresdkActivityCancellationType::WAIT_CANCELLATION_COMPLETED);

        if (($ns = (int) ($lo['ScheduleToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setScheduleToCloseTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($lo['StartToCloseTimeout'] ?? 0)) > 0) {
            $schedule->setStartToCloseTimeout(self::nsToDuration($ns));
        }
        if (($ns = (int) ($lo['LocalRetryThreshold'] ?? 0)) > 0) {
            $schedule->setLocalRetryThreshold(self::nsToDuration($ns));
        }

        if (\is_array($lo['RetryPolicy'] ?? null)) {
            $schedule->setRetryPolicy(self::retryPolicy($lo['RetryPolicy']));
        }

        $headers = $this->headerFields($command);
        if ($headers !== []) {
            $schedule->setHeaders($headers);
        }

        $this->run()['localActivities'][$seq] = [
            'id' => $command->getID(),
            'schedule' => $schedule->serializeToString(),
        ];

        return $this->withUserMetadata(
            (new WorkflowCommand())->setScheduleLocalActivity($schedule),
            (string) ($lo['Summary'] ?? ''),
        );
    }

    private function withUserMetadata(
        WorkflowCommand $command,
        string $summary = '',
        string $details = '',
    ): WorkflowCommand {
        if ($summary === '' && $details === '') {
            return $command;
        }

        $metadata = new UserMetadata();
        if ($summary !== '') {
            $metadata->setSummary($this->dataConverter->toPayload($summary));
        }
        if ($details !== '') {
            $metadata->setDetails($this->dataConverter->toPayload($details));
        }

        return $command->setUserMetadata($metadata);
    }

    /**
     * Core has no generic RecordMarker command for language SDKs. Preserve the
     * PHP SDK's side-effect contract with a private local activity: PHP executes
     * the callback only on a live activation and sends its encoded result as the
     * activity input; the activity loop echoes it, and Core records the local
     * activity result in history. During replay Core resolves the same stable
     * seq from that history instead of executing the activity, returning the
     * original value to the workflow.
     */
    private function scheduleSideEffect(RequestInterface $command): WorkflowCommand
    {
        $command->getPayloads()->setDataConverter($this->dataConverter);
        $seq = $this->seqFor($command->getID(), 'local-activity');

        $schedule = (new ScheduleLocalActivity())
            ->setSeq($seq)
            ->setActivityId("side-effect-{$seq}")
            ->setActivityType(ActivityTaskTranslator::SIDE_EFFECT_ACTIVITY_TYPE)
            ->setArguments($command->getPayloads()->toPayloads()->getPayloads())
            ->setAttempt(1)
            ->setScheduleToCloseTimeout(self::msToDuration(10_000))
            ->setStartToCloseTimeout(self::msToDuration(10_000))
            ->setRetryPolicy((new RetryPolicy())->setMaximumAttempts(1))
            ->setCancellationType(CoresdkActivityCancellationType::WAIT_CANCELLATION_COMPLETED);

        $serialized = $schedule->serializeToString();
        $this->run()['localActivities'][$seq] = [
            'id' => $command->getID(),
            'schedule' => $serialized,
        ];

        return $this->withUserMetadata(
            (new WorkflowCommand())->setScheduleLocalActivity($schedule),
            (string) ($command->getOptions()['summary'] ?? ''),
        );
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
