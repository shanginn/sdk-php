<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\ActivityTask\ActivityTask;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Worker\DispatcherInterface;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * The TrueAsync activity worker run loop.
 *
 * The Rust core long-polls the server on its own threads; we pull each ready
 * task off it, run the registered activity through the reused SDK dispatcher
 * ({@see DispatcherInterface}, i.e. the Router/InvokeActivity pipeline), and
 * hand the result back to the core. Every task runs in its own coroutine, so
 * activities that suspend on I/O execute concurrently on the single reactor
 * while the loop keeps polling for more.
 *
 * This class is only the transport loop; activity registration and execution
 * are the unchanged SDK. The matching RPC channel for heartbeats is wired into
 * the WorkerFactory separately.
 */
final class ActivityWorker
{
    private readonly ActivityTaskTranslator $translator;

    public function __construct(
        private readonly CoreWorker $core,
        private readonly DispatcherInterface $dispatcher,
        DataConverterInterface $dataConverter,
        string $taskQueue,
        private readonly ?CoreRpcConnection $rpc = null,
    ) {
        $this->translator = new ActivityTaskTranslator($dataConverter, $taskQueue);
    }

    /**
     * Poll-execute-complete until the core shuts down (poll returns null), then
     * drain any still-running activities before returning.
     */
    public function run(): void
    {
        $inflight = [];
        $live = [];
        $seq = 0;

        while (($bytes = $this->core->pollActivityTask()) !== null) {
            $task = new ActivityTask();
            $task->mergeFromString($bytes);
            $token = $task->getTaskToken();

            /* A cancel arrives as its own task while the start task's coroutine is
               still running; it carries no work and needs no completion, so record
               it inline (no coroutine) for the activity's next heartbeat to
               observe. Skip one whose activity has already finished — no heartbeat
               will read it, so recording it would leak (the core does not normally
               send this, but be defensive). The start side is always polled before
               its cancel, so the live token is registered by the time we get here. */
            if ($task->getVariant() === 'cancel') {
                if (isset($live[$token])) {
                    $this->rpc?->markCancellation($token, $task->getCancel());
                }
                continue;
            }

            $key = $seq++;
            $live[$token] = true;
            $inflight[$key] = \Async\spawn(function () use ($task, $key, $token, &$inflight, &$live): void {
                try {
                    $this->handle($task);
                } finally {
                    unset($inflight[$key], $live[$token]);
                }
            });
        }

        if ($inflight !== []) {
            \Async\await_all(\array_values($inflight));
        }
    }

    private function handle(ActivityTask $task): void
    {
        /* Cancels are handled inline in run(); only start tasks reach here. */
        $request = $this->translator->toServerRequest($task);

        if ($request === null) {
            return;
        }

        $token = $task->getTaskToken();
        $result = null;
        $error = null;

        try {
            $this->dispatcher->dispatch($request, [])->then(
                static function ($value) use (&$result): void { $result = $value; },
                static function (\Throwable $reason) use (&$error): void { $error = $reason; },
            );
        } catch (\Throwable $e) {
            $error = $e;
        }

        $completion = $error !== null
            ? $this->translator->failure($token, $error)
            : $this->translator->success($token, $result);

        try {
            $this->core->completeActivityTask($completion->serializeToString());
        } finally {
            $this->rpc?->forget($token);
        }
    }
}
