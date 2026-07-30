<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\DataConverter\DataConverterInterface;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Worker\DispatcherInterface;
use Temporal\WorkerFactory;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * Owns the structured lifetime of one native core worker.
 *
 * @internal
 */
final class NativeWorkerRuntime
{
    private readonly ?WorkflowWorker $workflowLoop;
    private readonly ?ActivityWorker $activityLoop;
    private readonly ?NexusWorker $nexusLoop;
    private bool $running = false;
    private bool $finalized = false;

    public function __construct(
        private readonly CoreWorker $core,
        WorkerFactory $factory,
        DispatcherInterface $worker,
        DataConverterInterface $dataConverter,
        string $taskQueue,
        ?CoreRpcConnection $rpc,
        bool $pollWorkflows = true,
        bool $pollActivities = true,
        ?NexusTaskHandler $nexusTaskHandler = null,
        string $namespace = 'default',
    ) {
        if (!$pollWorkflows && !$pollActivities && $nexusTaskHandler === null) {
            throw new \InvalidArgumentException(
                'A native worker must poll workflows, activities, Nexus tasks, or a combination.',
            );
        }

        $this->workflowLoop = $pollWorkflows
            ? new WorkflowWorker($core, $factory, $taskQueue)
            : null;
        $this->activityLoop = $pollActivities
            ? new ActivityWorker($core, $worker, $dataConverter, $taskQueue, $rpc)
            : null;
        $this->nexusLoop = $nexusTaskHandler === null
            ? null
            : new NexusWorker(
                $core,
                $nexusTaskHandler,
                $dataConverter,
                $namespace,
                $taskQueue,
            );
    }

    /**
     * Run all enabled poll loops as one structured scope.
     *
     * Any loop failure immediately requests core shutdown. Sibling loops then
     * drain, shutdown is finalized exactly once, and the original failure is
     * rethrown after cleanup.
     */
    public function run(): void
    {
        if ($this->running || $this->finalized) {
            throw new \LogicException('This native worker runtime cannot be run more than once.');
        }

        $this->running = true;
        $failure = null;
        $loops = [];

        $guard = function (callable $loop) use (&$failure): void {
            try {
                $loop();
            } catch (\Throwable $error) {
                $failure ??= $error;
                $this->shutdown();
            }
        };

        if ($this->workflowLoop !== null) {
            $loops[] = \Async\spawn(fn() => $guard($this->workflowLoop->run(...)));
        }
        if ($this->activityLoop !== null) {
            $loops[] = \Async\spawn(fn() => $guard($this->activityLoop->run(...)));
        }
        if ($this->nexusLoop !== null) {
            $loops[] = \Async\spawn(fn() => $guard($this->nexusLoop->run(...)));
        }

        $finalizeFailure = null;
        try {
            \Async\await_all_or_fail($loops);
        } finally {
            // Also covers cancellation of the coroutine running this method.
            try {
                $this->shutdown();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }

            try {
                \Async\protect(static fn() => \Async\await_all($loops));
            } catch (\Throwable $error) {
                $failure ??= $error;
            }

            try {
                $this->core->finalizeShutdown();
            } catch (\Throwable $error) {
                $finalizeFailure = $error;
            } finally {
                $this->running = false;
                $this->finalized = true;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
        if ($finalizeFailure !== null) {
            throw $finalizeFailure;
        }
    }

    public function shutdown(): void
    {
        if (!$this->finalized) {
            $this->core->initiateShutdown();
        }
    }
}
