<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\Activity_task\ActivityTask;
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
        $seq = 0;

        while (($bytes = $this->core->pollActivityTask()) !== null) {
            $task = new ActivityTask();
            $task->mergeFromString($bytes);

            $key = $seq++;
            $inflight[$key] = \Async\spawn(function () use ($task, $key, &$inflight): void {
                try {
                    $this->handle($task);
                } finally {
                    unset($inflight[$key]);
                }
            });
        }

        if ($inflight !== []) {
            \Async\await_all(\array_values($inflight));
        }
    }

    private function handle(ActivityTask $task): void
    {
        $request = $this->translator->toServerRequest($task);

        /* Non-start variants (cancel) are delivered out of band, not through the
           Router; the activity's own cancellation token handles them. */
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

        $this->core->completeActivityTask($completion->serializeToString());
    }
}
