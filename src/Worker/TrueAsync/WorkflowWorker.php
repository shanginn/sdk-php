<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * The TrueAsync workflow worker run loop.
 *
 * The Rust core long-polls the server on its own threads and hands us one
 * WorkflowActivation at a time; we run it through the reused deterministic
 * engine ({@see WorkflowWorkerFactory::processActivation}) and hand the
 * resulting WorkflowActivationCompletion back to the core.
 *
 * Unlike the activity loop, activations are processed inline rather than each in
 * its own coroutine: the engine is single-threaded and deterministic, so a
 * workflow task runs to its next await point and returns synchronously. The loop
 * itself parks the coroutine on the long-poll, so other coroutines (the activity
 * loop, the client) run while it waits for the next activation.
 *
 * This class is only the transport loop; workflow registration and execution are
 * the unchanged SDK.
 */
final class WorkflowWorker
{
    public function __construct(
        private readonly CoreWorker $core,
        private readonly WorkflowWorkerFactory $factory,
        private readonly string $taskQueue,
    ) {}

    /**
     * Poll-process-complete until the core shuts down (poll returns null).
     */
    public function run(): void
    {
        while (($activation = $this->core->pollWorkflowActivation()) !== null) {
            $completion = $this->factory->processActivation($activation, $this->taskQueue);
            $this->core->completeWorkflowActivation($completion);
        }
    }
}
