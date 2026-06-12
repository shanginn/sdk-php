<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
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

    /**
     * Apply one coresdk WorkflowActivation and return the serialized
     * WorkflowActivationCompletion. $taskQueue routes the activation's jobs to
     * the worker registered for that queue.
     */
    public function processActivation(string $activation, string $taskQueue): string
    {
        $codec = $this->workflowCodec ??= new CoresdkWorkflowCodec($this->converter);
        $headers = ['taskQueue' => $taskQueue];
        $tick = null;

        /* The determinism guard (DESIGN.md §7, layer 3). Legit workflow code
           never reaches the real reactor: it yields promises the engine
           resolves synchronously within the activation, so this coroutine
           never suspends while one is processed. The reactor is single-
           threaded, so the sentinel below can only run if the coroutine DOES
           suspend — workflow code performed real I/O, called Async\* directly,
           or awaited a non-workflow primitive. The task is then failed loudly
           instead of committing non-deterministic results. */
        $suspended = false;
        $sentinel = \Async\spawn(static function () use (&$suspended): void {
            $suspended = true;
        });

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

            if ($suspended) {
                throw new NonDeterministicWorkflowException(
                    'Workflow code suspended the worker coroutine: it reached the real reactor '
                    . '(direct Async\* usage, blocking I/O, or a non-workflow await). Workflow '
                    . 'code must be deterministic — use Workflow::timer(), '
                    . 'Workflow::executeActivity(), Workflow::await*() and the other '
                    . 'Workflow:: primitives instead.',
                );
            }

            return $codec->encodeStaged();
        } catch (\Throwable $e) {
            // The workflow task failed: a codec gap, an unmapped resolution, or an
            // engine/workflow-code error. Drop any commands queued before the throw
            // so they cannot leak into the next activation, then report the failure
            // so the core retries the task instead of waiting out a timeout.
            foreach ($this->responses as $ignored) {
            }

            return $codec->encodeFailure($e);
        } finally {
            /* Never ran on the clean path (the coroutine never yielded). */
            $sentinel->cancel();
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
                }
            }

            if ($synthesize === [] || $tick === null) {
                return;
            }

            foreach ($synthesize as $commandId) {
                $this->client->dispatch(new FailureResponse(
                    failure: new CanceledFailure('canceled'),
                    id: $commandId,
                    info: $tick,
                ));
            }

            $this->tick();
        } while (true);
    }
}
