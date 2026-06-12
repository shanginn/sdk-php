<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerInterface;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await_all;
use function Async\spawn;

/**
 * The idiomatic TrueAsync Temporal worker entry point: one object that serves a
 * task queue for both workflows and activities over a single Rust-core worker.
 *
 * A Temporal worker polls two streams — workflow activations and activity tasks.
 * {@see run()} drives both loops concurrently on the reactor: each long-poll
 * parks its coroutine, so a workflow scheduling an activity and that activity
 * executing make progress together on the single thread.
 *
 * Registration and execution are the unchanged SDK: one SDK Worker is both the
 * registry (workflow types, activity implementations) and the activity
 * DispatcherInterface, and the WorkflowWorkerFactory reuses it as the
 * deterministic engine.
 */
final class TemporalWorker
{
    private readonly DataConverterInterface $dataConverter;
    private readonly WorkflowWorkerFactory $factory;
    private readonly WorkerInterface $worker;
    private readonly WorkflowWorker $workflowLoop;
    private readonly ActivityWorker $activityLoop;

    public function __construct(
        private readonly CoreWorker $core,
        string $taskQueue,
        ?DataConverterInterface $dataConverter = null,
        ?RPCConnectionInterface $rpc = null,
    ) {
        $this->dataConverter = $dataConverter ?? DataConverter::createDefault();

        /* The default RPC channel answers activity heartbeats through the core
           and relays cancel tasks back into running activities. */
        $rpc ??= new CoreRpcConnection($core);

        $this->factory = WorkflowWorkerFactory::create($this->dataConverter, $rpc);
        $this->worker = $this->factory->newWorker($taskQueue);
        $this->workflowLoop = new WorkflowWorker($this->core, $this->factory, $taskQueue);
        $this->activityLoop = new ActivityWorker(
            $this->core,
            $this->worker,
            $this->dataConverter,
            $taskQueue,
            $rpc instanceof CoreRpcConnection ? $rpc : null,
        );
    }

    public function registerWorkflowTypes(string ...$class): self
    {
        $this->worker->registerWorkflowTypes(...$class);

        return $this;
    }

    public function registerActivityImplementations(object ...$activity): self
    {
        $this->worker->registerActivityImplementations(...$activity);

        return $this;
    }

    /**
     * Run both poll loops until the core shuts down, then finalize. Park this in
     * its own coroutine and call {@see shutdown()} from another to stop it.
     */
    public function run(): void
    {
        $loops = [
            spawn(fn() => $this->workflowLoop->run()),
            spawn(fn() => $this->activityLoop->run()),
        ];

        await_all($loops);

        $this->core->finalizeShutdown();
    }

    /**
     * Ask the core to stop. Both poll loops then drain to null and {@see run()}
     * returns. Safe to call from a signal handler or another coroutine.
     */
    public function shutdown(): void
    {
        $this->core->initiateShutdown();
    }
}
