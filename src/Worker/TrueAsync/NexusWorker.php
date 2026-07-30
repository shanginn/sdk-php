<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\Nexus\CancelNexusTask;
use Coresdk\Nexus\NexusTask;
use Coresdk\Nexus\NexusTaskCancelReason;
use Temporal\Api\Nexus\V1\Request;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\OperationException;
use Temporal\Nexus\Handler\MethodCanceller;
use Temporal\Nexus\NexusOperationContext;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * Native Core Nexus poll-execute-complete loop.
 *
 * Every handler request runs in its own coroutine. Core owns the concurrency
 * and poller limits; this loop keeps consuming issued tasks while the PHP
 * handlers suspend on TrueAsync I/O.
 *
 * @internal
 */
final class NexusWorker
{
    private readonly NexusTaskTranslator $translator;
    private readonly WallClockEnvironment $clock;

    public function __construct(
        private readonly CoreWorker|NexusCoreWorkerInterface $core,
        private readonly NexusTaskHandler $handler,
        DataConverterInterface $dataConverter,
        private readonly string $namespace,
        private readonly string $taskQueue,
    ) {
        $this->translator = new NexusTaskTranslator($dataConverter);
        $this->clock = new WallClockEnvironment();
    }

    /**
     * Poll until shutdown, translating user failures into Nexus completions
     * without terminating this worker.
     */
    public function run(): void
    {
        /** @var array<int, \Async\Coroutine> $inflight */
        $inflight = [];
        /** @var array<string, NexusTaskState> $states */
        $states = [];
        $seq = 0;
        $failure = null;
        $draining = false;

        $recordFailure = function (\Throwable $error) use (&$failure): void {
            if ($failure !== null) {
                return;
            }

            $failure = $error;
            try {
                $this->core->initiateShutdown();
            } catch (\Throwable) {
                // Preserve the causative transport/protocol failure.
            }
        };

        $startDraining = function () use (&$draining, &$states, $recordFailure): void {
            if ($draining) {
                return;
            }

            $draining = true;
            $this->cancelOutstanding($states, $recordFailure);
        };

        while (true) {
            try {
                // Reap before asking Core for another task. Once poll returns,
                // its bytes are a one-shot delivery and must not be abandoned
                // because an older coroutine completed exceptionally.
                $this->reapCompleted($inflight);
            } catch (\Throwable $error) {
                $recordFailure($error);
                $startDraining();
            }

            try {
                $bytes = $this->core->pollNexusTask();
            } catch (\Throwable $error) {
                $recordFailure($error);
                $startDraining();

                // Core's Nexus task manager is not shut down until its
                // language poll receives the shutdown sentinel. A transient
                // poll error must therefore not let this loop exit early.
                try {
                    \Async\delay(1);
                } catch (\Throwable $cancellation) {
                    // Cancellation of this worker coroutine is deferred until
                    // Core has observed its language-side shutdown poll. Keep
                    // the original poll/protocol failure authoritative.
                    $recordFailure($cancellation);
                }
                continue;
            }

            if ($bytes === null) {
                break;
            }

            if ($failure !== null) {
                $startDraining();
            }

            try {
                $task = new NexusTask();
                $task->mergeFromString($bytes);

                if ($task->getVariant() === 'cancel_task') {
                    $cancel = $task->getCancelTask();
                    \assert($cancel instanceof CancelNexusTask);
                    $this->cancel($cancel, $states);
                    continue;
                }

                if ($task->getVariant() !== 'task' || !$task->hasTask()) {
                    throw new \UnexpectedValueException(
                        'Core returned a Nexus task without a request or cancellation variant.',
                    );
                }

                $pollResponse = $task->getTask();
                \assert($pollResponse !== null);
                $token = $pollResponse->getTaskToken();

                if ($draining) {
                    // A request can already be issued when shutdown begins.
                    // Complete it without invoking application code so Core
                    // can release the token and finish its Nexus manager.
                    $this->core->completeNexusTask(
                        $this->translator->handlerFailure(
                            $token,
                            HandlerException::create(
                                ErrorType::Unavailable,
                                'Nexus worker is shutting down.',
                            ),
                        )->serializeToString(),
                    );
                    continue;
                }

                $request = $pollResponse->getRequest();
                $deadline = $task->hasRequestDeadline()
                    ? \DateTimeImmutable::createFromInterface($task->getRequestDeadline()->toDateTime())
                    : null;
                $state = new NexusTaskState(
                    new MethodCanceller($this->clock, $deadline),
                    $deadline,
                );
                $states[$token] = $state;

                $key = $seq++;
                $coroutine = \Async\spawn(function () use (
                    $task,
                    $token,
                    $request,
                    $state,
                    &$states,
                    $recordFailure,
                ): void {
                    $state->started = true;
                    try {
                        // Core cancellation may arrive before this newly
                        // spawned coroutine gets its first scheduler turn.
                        if ($state->cancelled) {
                            return;
                        }

                        $completion = $this->handle($task, $token, $request, $state);
                        if (!$state->cancelled) {
                            $this->core->completeNexusTask($completion->serializeToString());
                        }
                    } catch (\Throwable $error) {
                        if (!$state->cancelled) {
                            $recordFailure($error);
                        }
                    } finally {
                        unset($states[$token]);
                    }
                });
                $state->coroutine = $coroutine;
                $inflight[$key] = $coroutine;
            } catch (\Throwable $error) {
                $recordFailure($error);
                $startDraining();
            }
        }

        try {
            if ($inflight !== []) {
                \Async\protect(static fn() => \Async\await_all(\array_values($inflight)));
            }
        } catch (\Throwable $error) {
            $recordFailure($error);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function cancellationReason(int $reason): string
    {
        return match ($reason) {
            NexusTaskCancelReason::TIMED_OUT => 'Nexus handler request timed out.',
            NexusTaskCancelReason::WORKER_SHUTDOWN => 'Nexus worker is shutting down.',
            default => 'Nexus handler method was cancelled.',
        };
    }

    /**
     * @param array<int, \Async\Coroutine> $inflight
     */
    private function reapCompleted(array &$inflight): void
    {
        foreach ($inflight as $key => $coroutine) {
            if (!$coroutine->isCompleted()) {
                continue;
            }

            // Remove first so a rejected/cancelled coroutine cannot become a
            // permanently stale entry that fails every later reap attempt.
            unset($inflight[$key]);
            \Async\await($coroutine);
        }
    }

    private function handle(
        NexusTask $task,
        string $taskToken,
        ?Request $request,
        NexusTaskState $state,
    ): \Coresdk\Nexus\NexusTaskCompletion {
        try {
            if ($request === null) {
                throw HandlerException::create(
                    ErrorType::BadRequest,
                    'Nexus task does not contain a request.',
                );
            }

            $context = new NexusOperationContext(
                namespace: $this->namespace,
                taskQueue: $this->taskQueue,
                endpoint: $task->getEndpoint(),
            );

            return $this->translator->completed(
                $taskToken,
                $this->translator->invoke(
                    $this->handler,
                    $request,
                    $context,
                    $state->canceller,
                    $state->requestDeadline,
                ),
            );
        } catch (OperationException $error) {
            return $this->translator->operationFailure($taskToken, $error);
        } catch (HandlerException $error) {
            return $this->translator->handlerFailure($taskToken, $error);
        } catch (\Throwable) {
            return $this->translator->handlerFailure(
                $taskToken,
                HandlerException::create(ErrorType::Internal, 'Internal Nexus handler error'),
            );
        }
    }

    /**
     * @param array<string, NexusTaskState> $states
     */
    private function cancel(CancelNexusTask $cancel, array &$states): void
    {
        $token = $cancel->getTaskToken();
        $state = $states[$token] ?? null;

        if ($state !== null && !$state->cancelled) {
            $state->cancelled = true;
            try {
                $state->canceller->cancel(self::cancellationReason($cancel->getReason()));
            } catch (\Throwable) {
                // Cancellation-listener code belongs to the handler. It must
                // not prevent Core from releasing an expired/shutting-down
                // task or terminate unrelated Nexus polling.
            }

            if ($state->started) {
                try {
                    $state->coroutine?->cancel();
                } catch (\Throwable) {
                    // The acknowledgement below remains authoritative.
                }
            }
        }

        // A late cancellation is deliberately acknowledged too. Core treats a
        // completion for an already-finished token as an idempotent no-op.
        $this->core->completeNexusTask(
            $this->translator->acknowledgeCancellation($token)->serializeToString(),
        );
    }

    /**
     * A poll or protocol failure ends the only channel through which Core can
     * deliver WORKER_SHUTDOWN cancellation tasks. Cancel and acknowledge every
     * issued request locally before awaiting its coroutine, otherwise a
     * suspended handler can keep shutdown blocked forever.
     *
     * @param array<string, NexusTaskState> $states
     * @param \Closure(\Throwable): void $recordFailure
     */
    private function cancelOutstanding(array &$states, \Closure $recordFailure): void
    {
        foreach (\array_keys($states) as $token) {
            try {
                $this->cancel(
                    (new CancelNexusTask())
                        ->setTaskToken((string) $token)
                        ->setReason(NexusTaskCancelReason::WORKER_SHUTDOWN),
                    $states,
                );
            } catch (\Throwable $error) {
                // Keep draining all issued tasks and preserve the original
                // poll/protocol failure as the worker's reported cause.
                $recordFailure($error);
            }
        }
    }
}

/**
 * Mutable state shared by the poll loop and one handler coroutine.
 *
 * @internal
 */
final class NexusTaskState
{
    public bool $cancelled = false;
    public bool $started = false;
    public ?\Async\Coroutine $coroutine = null;

    public function __construct(
        public readonly MethodCanceller $canceller,
        public readonly ?\DateTimeImmutable $requestDeadline,
    ) {}
}
