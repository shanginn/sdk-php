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
use Temporal\Worker\Transport\Command\ServerRequestInterface;
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

        try {
            foreach ($codec->decode($activation, $headers) as $command) {
                $this->env->update($command->getTickInfo());

                if ($command instanceof ServerResponseInterface) {
                    $this->client->dispatch($command);
                    continue;
                }

                /* Queries bypass the Server: their request id must be the run id
                   (the route finds the process by it), so their outcome in the
                   generic ack queue would be indistinguishable from other acks.
                   Dispatch through the worker directly and capture the result
                   off the promise; it resolves on the ON_QUERY phase of tick()
                   below, and encode() emits the QueryResult command. */
                $queryId = $command instanceof ServerRequestInterface
                    ? ($command->getOptions()['queryId'] ?? null)
                    : null;
                if ($queryId !== null) {
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

            return $codec->encode($this->responses);
        } catch (\Throwable $e) {
            // The workflow task failed: a codec gap, an unmapped resolution, or an
            // engine/workflow-code error. Drop any commands queued before the throw
            // so they cannot leak into the next activation, then report the failure
            // so the core retries the task instead of waiting out a timeout.
            foreach ($this->responses as $ignored) {
            }

            return $codec->encodeFailure($e);
        }
    }
}
