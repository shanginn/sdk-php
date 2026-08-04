<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Workflow\Process;

use Internal\Destroy\Destroyable;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\DestructMemorizedInstanceException;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\TemporalFailure;
use Temporal\Exception\InvalidArgumentException;
use Temporal\Interceptor\WorkflowInbound\UpdateInput;
use Temporal\Internal\Declaration\MethodHandler;
use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Transport\Request\Cancel;
use Temporal\Internal\Transport\Request\RejectedOnCancelInterface;
use Temporal\Internal\Workflow\ScopeContext;
use Temporal\Internal\Workflow\WorkflowContext;
use Temporal\Worker\FeatureFlags;
use Temporal\Worker\LoopInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow;
use Temporal\Workflow\CancellationScopeInterface;

/**
 * A workflow Fiber and its cancellation scope are represented by one instance.
 *
 * @internal CoroutineScope is an internal library class, please do not use it in your code.
 * @psalm-internal Temporal\Internal
 * @implements CancellationScopeInterface<mixed>
 */
class Scope implements CancellationScopeInterface, Destroyable
{
    protected ServiceContainer $services;

    /**
     * Workflow context.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    protected WorkflowContext $context;

    /**
     * Coroutine scope context.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    protected ScopeContext $scopeContext;

    protected Deferred $deferred;

    /**
     * Workflow handler Fiber. It suspends only through {@see Awaiter} and is
     * resumed by this scope after the suspended promise settles.
     */
    protected DeferredFiber $coroutine;

    /**
     * Every workflow Fiber runs on its own loop layer.
     *
     * @var non-empty-string
     */
    private string $layer = LoopInterface::ON_TICK;

    /**
     * Each onCancel receives unique ID.
     */
    private int $cancelID = 0;

    /**
     * @var array<callable>
     */
    private array $onCancel = [];

    /**
     * @var array<callable(mixed): mixed>
     */
    private array $onClose = [];

    private bool $detached = false;
    private bool $cancelled = false;
    private bool $closed = false;
    private ?\Throwable $cancelReason = null;
    private bool $preserveCancellationFailure = false;

    public function __construct(
        ServiceContainer $services,
    ) {
        $this->services = $services;
        $this->deferred = new Deferred();
    }

    /**
     * @return non-empty-string
     */
    public function getLayer(): string
    {
        return $this->layer;
    }

    public function isDetached(): bool
    {
        return $this->detached;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getContext(): WorkflowContext
    {
        return $this->context;
    }

    /**
     * @param MethodHandler|\Closure(ValuesInterface): mixed $handler
     */
    public function start(MethodHandler|\Closure $handler, ValuesInterface $values, bool $deferred): void
    {
        // Create a managed workflow Fiber
        $this->coroutine = DeferredFiber::fromHandler($handler, $values, $this->scopeContext)
            ->catch($this->onException(...));

        $deferred
            ? $this->services->loop->once($this->layer, $this->next(...))
            : $this->next();
    }

    /**
     * @param callable(ValuesInterface): mixed $handler Update method handler.
     * @param Deferred $resolver Update method promise resolver.
     */
    public function startUpdate(callable $handler, UpdateInput $input, Deferred $resolver): void
    {
        // Update handler counter
        $id = $this->context->getHandlerState()->addUpdate($input->updateId, $input->updateName);
        $this->then(
            fn() => $this->context->getHandlerState()->removeUpdate($id),
            fn() => $this->context->getHandlerState()->removeUpdate($id),
        );

        $this->then(
            $resolver->resolve(...),
            function (\Throwable $error) use ($resolver): void {
                $this->services->exceptionInterceptor->isRetryable($error)
                    ? $this->scopeContext->panic($error)
                    : $resolver->reject($error);
            },
        );

        // Create a managed workflow Fiber
        $this->coroutine = $this->callSignalOrUpdateHandler($handler, $input->arguments);
        $this->next();
    }

    /**
     * @param callable(ValuesInterface): mixed $handler
     * @param non-empty-string $name
     */
    public function startSignal(callable $handler, ValuesInterface $values, string $name): void
    {
        // Update handler counter
        $id = $this->context->getHandlerState()->addSignal($name);
        $this->then(
            fn() => $this->context->getHandlerState()->removeSignal($id),
            fn() => $this->context->getHandlerState()->removeSignal($id),
        );

        // Create a managed workflow Fiber
        $this->coroutine = $this->callSignalOrUpdateHandler($handler, $values);
        $this->next();
    }

    public function onCancel(callable $then): self
    {
        $this->addOnCancel($then);
        return $this;
    }

    /**
     * @param callable(mixed): mixed $then An exception instance is passed in case of error.
     * @return $this
     */
    public function onClose(callable $then): self
    {
        $this->onClose[] = $then;
        return $this;
    }

    public function cancel(?\Throwable $reason = null): void
    {
        if ($this->cancelled || $this->closed) {
            return;
        }

        $this->cancelled = true;
        $this->cancelReason = $reason;

        try {
            $previousContext = Workflow::getCurrentContext();
        } catch (\Throwable) {
            $previousContext = null;
        }

        try {
            foreach ($this->onCancel as $i => $handler) {
                $this->makeCurrent();
                unset($this->onCancel[$i]);
                $handler($reason);
            }
        } finally {
            Workflow::setCurrentContext($previousContext);
        }
    }

    /**
     * @param non-empty-string|null $layer
     */
    public function startScope(callable $handler, bool $detached, ?string $layer = null): CancellationScopeInterface
    {
        $scope = $this->createScope($detached, $layer);
        try {
            $scope->start($handler(...), EncodedValues::empty(), false);
        } finally {
            // Starting a child runs it immediately up to its first
            // deterministic suspension point. Always rebind the parent even
            // if child startup or a condition predicate throws.
            $this->makeCurrent();
        }

        return $scope;
    }

    public function promise(): PromiseInterface
    {
        return $this->deferred->promise();
    }

    public function await(): mixed
    {
        return Awaiter::await($this);
    }

    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null,
        ?callable $onProgress = null,
    ): PromiseInterface {
        return $this->deferred->promise()->then($onFulfilled, $onRejected);
    }

    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->deferred->promise()->catch($onRejected);
    }

    public function finally(callable $onFulfilledOrRejected): PromiseInterface
    {
        return $this->deferred->promise()->finally($onFulfilledOrRejected);
    }

    /**
     * @deprecated use {@see catch()} instead
     */
    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->catch($onRejected);
    }

    /**
     * @deprecated use {@see finally()} instead
     */
    public function always(callable $onFulfilledOrRejected): PromiseInterface
    {
        return $this->finally($onFulfilledOrRejected);
    }

    /**
     * Connects promise to scope context to be cancelled on promise cancel.
     */
    public function onAwait(Deferred $deferred): void
    {
        $cancelID = $this->addOnCancel(static function (?\Throwable $e = null) use ($deferred): void {
            $deferred->reject($e ?? new CanceledFailure(''));
        });

        // do not cancel already complete promises
        $cleanup = function () use ($cancelID): void {
            $this->makeCurrent();
            $this->context->resolveConditions();
            unset($this->onCancel[$cancelID]);
        };

        $deferred->promise()->then($cleanup, $cleanup);
    }

    public function destroy(): void
    {
        $this->context?->destroy();
        $this->scopeContext?->destroy();
        unset(
            $this->coroutine,
            $this->context,
            $this->scopeContext,
            $this->deferred,
            $this->services,
            $this->onCancel,
            $this->onClose,
        );
    }

    /**
     * @param non-empty-string|null $layer
     */
    protected function createScope(
        bool $detached,
        ?string $layer = null,
        ?WorkflowContext $context = null,
        ?Workflow\UpdateContext $updateContext = null,
    ): self {
        $scope = new Scope($this->services);
        $scope->setContext($context ?? $this->context, $updateContext);
        $scope->detached = $detached;

        if ($layer !== null) {
            $scope->layer = $layer;
        }

        $cancelID = $this->addOnCancel($scope->cancelFromParent(...));

        $scope->onClose(
            function () use ($cancelID): void {
                unset($this->onCancel[$cancelID]);
            },
        );

        return $scope;
    }

    protected function setContext(WorkflowContext $ctx, ?Workflow\UpdateContext $updateContext = null): void
    {
        $this->context = $ctx;
        $this->scopeContext = ScopeContext::fromWorkflowContext(
            $this->context,
            $this,
            $this->onRequest(...),
            $updateContext,
        );
    }

    /**
     * Call a Signal or Update method. In this case deserialization errors are skipped.
     *
     * @param callable(ValuesInterface): mixed $handler
     */
    protected function callSignalOrUpdateHandler(callable $handler, ValuesInterface $values): DeferredFiber
    {
        return DeferredFiber::fromHandler(
            static function (ValuesInterface $values) use ($handler): mixed {
                try {
                    return $handler($values);
                } catch (InvalidArgumentException) {
                    // Skip deserialization errors
                    return null;
                }
            },
            $values,
            $this->scopeContext,
        )->catch($this->onException(...));
    }

    protected function onRequest(RequestInterface $request, PromiseInterface $promise, bool $cancellable = true): void
    {
        $cancelID = $this->addOnCancel(function (?\Throwable $reason = null) use ($request, $cancellable): void {
            $client = $this->context->getClient();
            if ($reason instanceof DestructMemorizedInstanceException) {
                // memory flush
                $client->reject($request, $reason);
                return;
            }

            if (!$cancellable) {
                if ($request instanceof RejectedOnCancelInterface) {
                    $client->reject($request, new CanceledFailure('nexus operation cancelled'));
                }

                return;
            }

            if ($client->isQueued($request)) {
                $client->cancel($request);
                return;
            }

            $client->send(new Cancel($request->getID()));
        }, $cancellable);

        // do not cancel already complete promises
        $cleanup = function () use ($cancelID): void {
            $this->makeCurrent();
            $this->context->resolveConditions();
            unset($this->onCancel[$cancelID]);
        };

        $promise->then($cleanup, $cleanup);
    }

    protected function makeCurrent(): void
    {
        Workflow::setCurrentContext($this->scopeContext);
    }

    protected function next(): void
    {
        $this->makeCurrent();
        $this->context->resolveConditions();

        try {
            $suspended = $this->coroutine->start();
        } catch (\Throwable) {
            return;
        }

        $this->advance($suspended);
    }

    private function advance(mixed $suspended): void
    {
        $this->makeCurrent();
        $this->context->resolveConditions();

        if ($this->coroutine->isTerminated()) {
            try {
                $this->onResult($this->coroutine->getReturn());
            } catch (\Throwable $e) {
                $this->onException($e);
            }
            return;
        }

        if (!$this->coroutine->isSuspended()) {
            $this->onException(new \LogicException('Managed workflow Fiber is neither suspended nor terminated.'));
            return;
        }

        if (!$suspended instanceof FiberSuspension) {
            $type = \get_debug_type($suspended);
            $this->onException(new \LogicException(
                "Managed workflow Fiber suspended with unsupported value of type `$type`; "
                . 'expected an Awaiter suspension instruction.',
            ));
            return;
        }

        $this->preserveCancellationFailure = $suspended->preserveCancellationFailure;
        $this->nextPromise($suspended->promise, $suspended->interruptOnCancel);
    }

    private function addOnCancel(callable $handler, bool $cancellable = true): int
    {
        $id = ++$this->cancelID;

        if (FeatureFlags::$propagateCancellationToNewScopes && $this->cancelled && $cancellable) {
            try {
                $previousContext = Workflow::getCurrentContext();
            } catch (\Throwable) {
                $previousContext = null;
            }

            try {
                $this->makeCurrent();
                $handler($this->cancelReason);
            } finally {
                Workflow::setCurrentContext($previousContext);
            }
            return $id;
        }

        $this->onCancel[$id] = $handler;
        return $id;
    }

    private function nextPromise(PromiseInterface $promise, bool $interruptOnCancel): void
    {
        if (
            $promise instanceof CancellationScopeInterface
            && $promise->isCancelled()
            && (!$promise instanceof self || !$promise->preserveCancellationFailure)
        ) {
            $reason = FeatureFlags::$propagateCancellationToNewScopes && $promise instanceof self
                ? $promise->cancelReason
                : null;
            $this->handleError($reason ?? new CanceledFailure(''));
            return;
        }

        $settled = false;
        $cancelID = null;

        if ($interruptOnCancel) {
            $cancelID = $this->addOnCancel(function (?\Throwable $reason = null) use (&$settled): void {
                if ($settled) {
                    return;
                }

                $settled = true;
                $this->defer(
                    fn() => $this->handleError($reason ?? new CanceledFailure('')),
                );
            });
        }

        $cleanup = function () use (&$cancelID): void {
            if ($cancelID !== null) {
                unset($this->onCancel[$cancelID]);
                $cancelID = null;
            }
        };

        $onFulfilled = function (mixed $result) use (&$settled, $cleanup): mixed {
            if ($settled) {
                return $result;
            }

            $settled = true;
            $cleanup();
            $this->defer(
                function () use ($result): void {
                    $this->makeCurrent();
                    try {
                        $suspended = $this->coroutine->resume($result);
                    } catch (\Throwable) {
                        return;
                    }

                    $this->advance($suspended);
                },
            );

            return $result;
        };

        $onRejected = function (\Throwable $e) use (&$settled, $cleanup): void {
            if ($settled) {
                throw $e;
            }

            $settled = true;
            $cleanup();
            $this->defer(
                function () use ($e): void {
                    if ($e instanceof TemporalFailure && !$e->hasOriginalStackTrace()) {
                        $e->setOriginalStackTrace($this->context->getStackTrace());
                    }

                    $this->handleError($e);
                },
            );

            throw $e;
        };

        $promise
            ->then($onFulfilled, $onRejected)
            // Handle last error
            ->then(null, static fn(\Throwable $e) => null);
    }

    /**
     * Send error into the coroutine. If the code inside handles exception
     * we continue the flow. If the exception is bubbled up - the scope
     * itself handles it.
     */
    private function handleError(\Throwable $e): void
    {
        $this->makeCurrent();

        try {
            $suspended = $this->coroutine->throw($e);
        } catch (\Throwable) {
            return;
        }

        $this->advance($suspended);
    }

    private function onException(\Throwable $e): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->makeCurrent();
        $this->deferred->reject($e);
        $this->context->resolveConditions();

        $this->releaseExecutionState($e);
    }

    private function onResult(mixed $result): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->makeCurrent();
        $this->deferred->resolve($result);
        $this->context->resolveConditions();

        $this->releaseExecutionState($result);
    }

    private function releaseExecutionState(mixed $result): void
    {
        $onClose = $this->onClose;
        $this->onClose = [];
        $this->onCancel = [];
        unset($this->coroutine);

        try {
            foreach ($onClose as $close) {
                $close($result);
            }
        } finally {
            $this->scopeContext->releaseScope();
        }
    }

    private function defer(\Closure $tick): void
    {
        $this->services->loop->once($this->layer, $tick);
        $this->services->queue->count() === 0 and $this->services->loop->tick();
    }

    private function cancelFromParent(?\Throwable $reason = null): void
    {
        if ($this->detached && !$reason instanceof DestructMemorizedInstanceException) {
            return;
        }

        $this->cancel($reason);
    }
}
