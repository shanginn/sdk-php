<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Worker\Transport\Command\Client\UpdateResponse;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\Transport\Command\Server\TickInfo;
use Temporal\Worker\Transport\Command\ServerResponseInterface;

/**
 * A WorkerFactory wired for the Temporal Rust core instead of RoadRunner: it
 * speaks the coresdk workflow protobuf through {@see CoresdkWorkflowCodec}, and
 * exposes a single-activation entry point the TrueAsync transport loop drives
 * (rather than RoadRunner's waitBatch/send host).
 *
 * The deterministic workflow engine — the Router workflow routes, RunningWorkflows,
 * the command queue, the loop — is reused unchanged. processActivation mirrors the
 * private RoadRunner dispatch step: decode the activation into the SDK command
 * stream, drive each job (server request) or resolution (server response), tick
 * the loop so the workflow advances, then encode the outgoing commands back into a
 * coresdk completion.
 *
 * The codec is held here rather than swapped into the parent's $codec: the
 * parent builds that one in its (private, non-overridable) constructor path for
 * the RoadRunner transport, which we bypass entirely.
 */
final class WorkflowWorkerFactory extends \Temporal\WorkerFactory
{
    private ?CoresdkWorkflowCodec $workflowCodec = null;

    /** A workflow task whose processing blocks the worker coroutine past this
     *  many ms reached the real reactor (Async\* / blocking I/O) instead of a
     *  deterministic Workflow:: primitive. Mirrors the Go SDK's deadlock timeout. */
    private const DETERMINISM_BUDGET_MS = 1000;

    /**
     * Apply one coresdk WorkflowActivation and return the serialized
     * WorkflowActivationCompletion. $taskQueue routes the activation's jobs to
     * the worker registered for that queue.
     */
    public function processActivation(string $activation, string $taskQueue): string
    {
        $codec = $this->workflowCodec ??= new CoresdkWorkflowCodec($this->converter);

        /* Determinism guard, Go-style — the analog of the Go SDK dispatcher's
           deadlock detector. Correct workflow code never blocks the worker
           coroutine on the real reactor: every wait is a Workflow:: primitive the
           engine resolves synchronously from history within the activation, so
           applying it runs straight through to a completion. We run it in a child
           coroutine and race it against a budget; if the budget elapses while the
           child is still blocked, workflow code reached the real reactor (direct
           Async\*, blocking I/O, a non-workflow await) and we fail the task
           instead of committing a non-deterministic result. (Detecting the mere
           *fact* of a coroutine switch would be wrong — a benign momentary switch,
           e.g. a fire-and-forget command, resumes far within the budget; only a
           lasting block is a violation, exactly as in Go.) */
        $work = \Async\spawn(fn(): string => $this->applyActivation($codec, $activation, $taskQueue));
        $deadline = \Async\spawn(static fn() => \Async\delay(self::DETERMINISM_BUDGET_MS));

        try {
            $completion = \Async\await($work, $deadline);
            $deadline->cancel();

            return $completion;
        } catch (\Throwable $overBudget) {
            $work->cancel();

            return $codec->encodeFailure(new NonDeterministicWorkflowException(\sprintf(
                'Workflow task exceeded the determinism budget (%d ms): workflow code '
                . 'blocked the worker coroutine on the real reactor (direct Async\\* usage, '
                . 'blocking I/O, or a non-workflow await). Workflow code must be deterministic '
                . '— use Workflow::timer(), Workflow::executeActivity(), Workflow::await*() and '
                . 'the other Workflow:: primitives instead.',
                self::DETERMINISM_BUDGET_MS,
            )));
        }
    }

    /**
     * Drive one activation through the reused engine: decode it into the SDK
     * command stream, dispatch each job (server request) or resolution (server
     * response), tick the loop, then encode the outgoing commands back into a
     * coresdk completion. Runs inside the guard's child coroutine (see
     * {@see processActivation}). Never throws — engine/codec errors become a
     * failed completion so the core retries the task.
     */
    private function applyActivation(CoresdkWorkflowCodec $codec, string $activation, string $taskQueue): string
    {
        $headers = ['taskQueue' => $taskQueue];
        $tick = null;

        try {
            foreach ($codec->decode($activation, $headers) as $command) {
                $tick = $command->getTickInfo();
                $this->env->update($tick);

                if ($command instanceof ServerResponseInterface) {
                    $this->client->dispatch($command);
                    continue;
                }

                /* Queries bypass the Server: their request id must be the run id
                   (the route finds the process by it), so their outcome in the
                   generic ack queue would be indistinguishable from other acks.
                   Dispatch through the worker directly and capture the result
                   off the promise; it resolves on the ON_QUERY phase of tick()
                   below, and the codec emits the QueryResult command. */
                if ($command instanceof QueryServerRequest) {
                    $queryId = $command->queryId;
                    $worker = $this->queues->find($taskQueue) ?? throw new \LogicException(
                        "no worker registered for task queue {$taskQueue}",
                    );
                    $worker->dispatch($command, $headers)->then(
                        static fn(?ValuesInterface $values) => $codec->recordQuerySuccess($queryId, $values),
                        static fn(\Throwable $e) => $codec->recordQueryFailure($queryId, $e),
                    );
                    continue;
                }

                $this->server->dispatch($command, $headers);
            }

            $this->tick();
            $this->drainIntoCodec($codec, $tick);

            return $codec->encodeStaged();
        } catch (\Throwable $e) {
            /* The workflow task failed: a codec gap, an unmapped resolution, or an
               engine/workflow-code error. Drop any commands queued before the throw
               so they cannot leak into the next activation, then report the failure
               so the core retries the task instead of waiting out a timeout. */
            foreach ($this->responses as $ignored) {
            }

            return $codec->encodeFailure($e);
        }
    }

    /**
     * Drain the outgoing queue into the codec, replaying the role the RR host
     * played for cancelled commands. The core never resolves a cancelled timer
     * (CancelTimer is fire-and-forget, unlike an activity cancel), so when
     * staging reports such ids we reject their promises with a CanceledFailure
     * here and tick again — the workflow observes the cancel at its current
     * await and can issue its next commands (e.g. CompleteWorkflow) within the
     * same activation. Without this, a workflow parked on a cancelled timer
     * would hang forever. Repeats until a pass synthesizes nothing new.
     *
     * getVersion is resolved the same way: it has no server round-trip, so the
     * codec computes the version and we dispatch it straight back as a
     * SuccessResponse, then tick so the workflow continues on its chosen branch.
     */
    private function drainIntoCodec(CoresdkWorkflowCodec $codec, ?TickInfo $tick): void
    {
        do {
            $synthesize = [];
            foreach ($this->responses as $response) {
                if ($response instanceof RequestInterface) {
                    foreach ($codec->stage($response) as $commandId) {
                        $synthesize[] = $commandId;
                    }
                } elseif ($response instanceof UpdateResponse) {
                    /* Update replies are ResponseInterface, not RequestInterface,
                       so stage() never sees them — the role the RR host played.
                       Route each phase (validated/completed) into the codec. */
                    $codec->stageUpdateResponse($response);
                }
            }

            $versions = $codec->drainVersionResolutions();

            if (($synthesize === [] && $versions === []) || $tick === null) {
                return;
            }

            foreach ($synthesize as $commandId) {
                $this->client->dispatch(new FailureResponse(
                    failure: new CanceledFailure('canceled'),
                    id: $commandId,
                    info: $tick,
                ));
            }

            foreach ($versions as $version) {
                $this->client->dispatch(new SuccessResponse(
                    values: EncodedValues::fromValues([$version['version']], $this->converter),
                    id: $version['id'],
                    info: $tick,
                ));
            }

            $this->tick();
        } while (true);
    }
}
