<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Workflow\Process\Fiber;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use Temporal\Exception\DestructMemorizedInstanceException;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\TemporalFailure;
use Temporal\Internal\Promise\DeferredPromise;
use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Transport\CompletableResultInterface;
use Temporal\Internal\Transport\Request\Cancel;
use Temporal\Internal\Workflow\Process\HandlerState;
use Temporal\Internal\Workflow\Process\Process;
use Temporal\Internal\Workflow\ScopeContext;
use Temporal\Internal\Workflow\WorkflowContext;
use Temporal\Promise;
use Temporal\Worker\LoopInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow;
use Temporal\Workflow\CancellationScopeInterface;
use Temporal\Workflow\UpdateContext;

/**
 * A fiber-based implementation of a scope that replaces the generator-based implementation.
 *
 * @implements CancellationScopeInterface<mixed>
 */
class FiberScope implements CancellationScopeInterface
{
    protected ServiceContainer $services;
    
    /**
     * Workflow context.
     */
    protected WorkflowContext $context;
    
    /**
     * Scope context.
     */
    protected ScopeContext $scopeContext;
    
    /**
     * Promise that resolves when the scope completes.
     */
    private DeferredPromise $deferred;
    
    /**
     * Every scope runs on its own loop layer.
     */
    private string $layer = LoopInterface::ON_TICK;
    
    /**
     * Each onCancel receives unique ID.
     */
    private int $cancelID = 0;
    
    /**
     * Cancellation handlers.
     *
     * @var array<callable>
     */
    private array $onCancel = [];
    
    /**
     * Completion handlers.
     *
     * @var array<callable(mixed): mixed>
     */
    private array $onClose = [];
    
    /**
     * Whether this scope is detached from its parent.
     */
    private bool $detached = false;
    
    /**
     * Whether this scope has been cancelled.
     */
    private bool $cancelled = false;
    
    /**
     * The fiber running the scope's callable.
     */
    private ?\Fiber $fiber = null;
    
    /**
     * Create a new fiber scope.
     */
    public function __construct(
        ServiceContainer $services,
        WorkflowContext $ctx,
        ?UpdateContext $updateContext = null,
    ) {
        $this->context = $ctx;
        $this->scopeContext = ScopeContext::fromWorkflowContext(
            $this->context,
            $this,
            $this->onRequest(...),
            $updateContext,
        );
        
        $this->services = $services;
        $this->deferred = new DeferredPromise();
    }
    
    /**
     * @return non-empty-string
     */
    public function getLayer(): string
    {
        return $this->layer;
    }
    
    /**
     * Whether this scope is detached from its parent.
     */
    public function isDetached(): bool
    {
        return $this->detached;
    }
    
    /**
     * Whether this scope has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
    
    /**
     * Get the workflow context.
     */
    public function getContext(): WorkflowContext
    {
        return $this->context;
    }
    
    /**
     * Start the scope with the given handler.
     *
     * @param callable $handler The handler to execute.
     * @param array $args Arguments to pass to the handler.
     * @param bool $deferred Whether to defer execution.
     */
    public function start(callable $handler, array $args = [], bool $deferred = false): void
    {
        if ($deferred) {
            $this->services->loop->once($this->layer, fn() => $this->runInFiber($handler, $args));
        } else {
            $this->runInFiber($handler, $args);
        }
    }
    
    /**
     * Start a signal handler.
     *
     * @param callable $handler The signal handler.
     * @param array $args Arguments for the handler.
     * @param non-empty-string $name Signal name.
     */
    public function startSignal(callable $handler, array $args = [], string $name = ''): void
    {
        // Increment signal counter
        $id = $this->context->getHandlerState()->addSignal($name);
        $this->then(
            fn() => $this->context->getHandlerState()->removeSignal($id),
            fn() => $this->context->getHandlerState()->removeSignal($id),
        );
        
        // Run the handler
        $this->runInFiber($handler, $args);
    }
    
    /**
     * Start an update handler.
     *
     * @param callable $handler The update handler.
     * @param array $args Arguments for the handler.
     * @param DeferredPromise $resolver The deferred to resolve when the update completes.
     * @param string $updateId Update ID.
     * @param string $updateName Update name.
     */
    public function startUpdate(
        callable $handler, 
        array $args = [], 
        DeferredPromise $resolver,
        string $updateId = '',
        string $updateName = ''
    ): void {
        // Update handler counter
        $id = $this->context->getHandlerState()->addUpdate($updateId, $updateName);
        $this->then(
            fn() => $this->context->getHandlerState()->removeUpdate($id),
            fn() => $this->context->getHandlerState()->removeUpdate($id),
        );
        
        // Propagate results to the resolver
        $this->then(
            $resolver->resolve(...),
            function (\Throwable $error) use ($resolver): void {
                $this->services->exceptionInterceptor->isRetryable($error)
                    ? $this->scopeContext->panic($error)
                    : $resolver->reject($error);
            },
        );
        
        // Run the handler
        $this->runInFiber($handler, $args);
    }
    
    /**
     * Run a scope with a callable.
     * 
     * @param callable $handler The handler to execute.
     * @param bool $detached Whether this scope is detached.
     * @param string|null $layer The loop layer to run on.
     * @return CancellationScopeInterface
     */
    public function startScope(callable $handler, bool $detached, ?string $layer = null): CancellationScopeInterface
    {
        $scope = $this->createScope($detached, $layer);
        $scope->start($handler, []);
        
        return $scope;
    }
    
    /**
     * Get a promise that resolves when the scope completes.
     * 
     * @return Promise<mixed>
     */
    public function promise(): Promise
    {
        return $this->deferred->promise();
    }
    
    /**
     * Register a fulfillment or rejection handler for the scope.
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null,
        ?callable $onProgress = null,
    ): Promise {
        return $this->deferred->promise()->then($onFulfilled, $onRejected);
    }
    
    /**
     * Register a rejection handler for this scope.
     */
    public function catch(callable $onRejected): Promise
    {
        return $this->deferred->promise()->catch($onRejected);
    }
    
    /**
     * Register a completion handler for this scope.
     */
    public function finally(callable $onFulfilledOrRejected): Promise
    {
        return $this->deferred->promise()->finally($onFulfilledOrRejected);
    }
    
    /**
     * @deprecated Use catch() instead.
     */
    public function otherwise(callable $onRejected): Promise
    {
        return $this->catch($onRejected);
    }
    
    /**
     * @deprecated Use finally() instead.
     */
    public function always(callable $onFulfilledOrRejected): Promise
    {
        return $this->finally($onFulfilledOrRejected);
    }
    
    /**
     * Register a cancellation handler.
     *
     * @param callable $then The handler to call when this scope is cancelled.
     * @return $this
     */
    public function onCancel(callable $then): self
    {
        $this->onCancel[++$this->cancelID] = $then;
        return $this;
    }
    
    /**
     * Register a completion handler.
     *
     * @param callable $then The handler to call when this scope completes.
     * @return $this
     */
    public function onClose(callable $then): self
    {
        $this->onClose[] = $then;
        return $this;
    }
    
    /**
     * Cancel this scope.
     *
     * @param \Throwable|null $reason The reason for cancellation.
     */
    public function cancel(?\Throwable $reason = null): void
    {
        if ($this->detached && !$reason instanceof DestructMemorizedInstanceException) {
            // Detached scopes can be offloaded via memory flush
            return;
        }
        
        if ($this->cancelled) {
            return;
        }
        
        $this->cancelled = true;
        
        // Call cancellation handlers
        foreach ($this->onCancel as $i => $handler) {
            $this->makeCurrent();
            unset($this->onCancel[$i]);
            $handler($reason);
        }
        
        // If we have a fiber, throw a cancellation exception into it
        if ($this->fiber !== null && $this->fiber->isStarted() && !$this->fiber->isTerminated()) {
            try {
                $this->fiber->throw($reason ?? new CanceledFailure('Cancelled'));
            } catch (\Throwable $e) {
                // Fiber already terminated or in a state where we cannot throw into it
            }
        }
    }
    
    /**
     * Connect a deferred to this scope to be cancelled when the scope is cancelled.
     */
    public function onAwait(DeferredPromise $deferred): void
    {
        $this->onCancel[++$this->cancelID] = static function (?\Throwable $e = null) use ($deferred): void {
            $deferred->reject($e ?? new CanceledFailure(''));
        };
        
        $cancelID = $this->cancelID;
        
        // Do not cancel already complete promises
        $cleanup = function () use ($cancelID): void {
            $this->makeCurrent();
            $this->context->resolveConditions();
            unset($this->onCancel[$cancelID]);
        };
        
        $deferred->promise()->then($cleanup, $cleanup);
    }
    
    /**
     * Handle a request from the context.
     */
    protected function onRequest(RequestInterface $request, Promise $promise): void
    {
        $this->onCancel[++$this->cancelID] = function (?\Throwable $reason = null) use ($request): void {
            if ($reason instanceof DestructMemorizedInstanceException) {
                // Memory flush
                $this->context->getClient()->reject($request, $reason);
                return;
            }
            
            if ($this->context->getClient()->isQueued($request)) {
                $this->context->getClient()->cancel($request);
                return;
            }
            
            $this->context->getClient()->request(new Cancel($request->getID()), $this->scopeContext);
        };
        
        $cancelID = $this->cancelID;
        
        // Do not cancel already complete promises
        $cleanup = function () use ($cancelID): void {
            $this->makeCurrent();
            $this->context->resolveConditions();
            unset($this->onCancel[$cancelID]);
        };
        
        $promise->then($cleanup, $cleanup);
    }
    
    /**
     * Create a child scope.
     *
     * @param bool $detached Whether this scope is detached.
     * @param string|null $layer The loop layer to run on.
     * @param WorkflowContext|null $context The workflow context to use.
     * @param UpdateContext|null $updateContext The update context to use.
     * @return FiberScope The new scope.
     */
    protected function createScope(
        bool $detached,
        ?string $layer = null,
        ?WorkflowContext $context = null,
        ?UpdateContext $updateContext = null,
    ): FiberScope {
        $className = $this instanceof Process ? Process::class : FiberScope::class;
        
        $scope = new $className($this->services, $context ?? $this->context, $updateContext);
        $scope->detached = $detached;
        
        if ($layer !== null) {
            $scope->layer = $layer;
        }
        
        $cancelID = ++$this->cancelID;
        $this->onCancel[$cancelID] = $scope->cancel(...);
        
        $scope->onClose(
            function () use ($cancelID): void {
                unset($this->onCancel[$cancelID]);
            },
        );
        
        return $scope;
    }
    
    /**
     * Set this scope's context as current.
     */
    protected function makeCurrent(): void
    {
        Workflow::setCurrentContext($this->scopeContext);
    }
    
    /**
     * Run the handler in a fiber.
     *
     * @param callable $handler The handler to execute.
     * @param array $args Arguments to pass to the handler.
     */
    private function runInFiber(callable $handler, array $args): void
    {
        $this->makeCurrent();
        
        try {
            // Create and start a new fiber
            $this->fiber = new \Fiber(function () use ($handler, $args) {
                try {
                    // Call the handler with the arguments
                    $result = $handler(...$args);
                    
                    // Resolve the deferred with the result
                    $this->onResult($result);
                } catch (\Throwable $e) {
                    // Reject the deferred if the handler throws
                    $this->onException($e);
                }
            });
            
            // Start the fiber
            $this->fiber->start();
            
            // Check if the fiber is suspended (waiting for a Future to resolve)
            if ($this->fiber->isSuspended()) {
                $this->handleSuspendedFiber();
            }
        } catch (\Throwable $e) {
            // Handle any exceptions that occur during fiber creation/startup
            $this->onException($e);
        }
    }
    
    /**
     * Handle a suspended fiber.
     * This manages the cycle of resuming the fiber when awaited futures/promises complete.
     */
    private function handleSuspendedFiber(): void
    {
        try {
            // Get the Future or Promise that was yielded from the fiber
            $yielded = $this->fiber->suspend();
            
            // Check what kind of object was yielded
            if ($yielded instanceof Future || $yielded instanceof Promise) {
                // Convert to our Promise type if needed
                if ($yielded instanceof Future && !$yielded instanceof Promise) {
                    $yielded = new Promise($yielded->state);
                }
                
                // When the promise resolves or rejects, resume the fiber with the result
                $yielded
                    ->then(
                        function ($value) {
                            if (!$this->fiber->isTerminated()) {
                                $this->defer(function () use ($value) {
                                    $this->makeCurrent();
                                    try {
                                        $this->fiber->resume($value);
                                        
                                        // If the fiber is still suspended, handle that
                                        if ($this->fiber->isSuspended()) {
                                            $this->handleSuspendedFiber();
                                        }
                                    } catch (\Throwable $e) {
                                        $this->onException($e);
                                    }
                                });
                            }
                            return $value;
                        }, 
                        function (\Throwable $e) {
                            if (!$this->fiber->isTerminated()) {
                                $this->defer(function () use ($e) {
                                    $this->makeCurrent();
                                    try {
                                        // Throw the exception into the fiber
                                        $this->fiber->throw($e);
                                        
                                        // If the fiber is still suspended, handle that
                                        if ($this->fiber->isSuspended()) {
                                            $this->handleSuspendedFiber();
                                        }
                                    } catch (\Throwable $innerException) {
                                        // If throwing into the fiber fails, reject the deferred
                                        $this->onException($innerException);
                                    }
                                });
                            }
                            throw $e;
                        }
                    )
                    ->ignore();
            } else {
                // For other types, just resume the fiber with the value
                $this->defer(function () use ($yielded) {
                    $this->makeCurrent();
                    try {
                        $this->fiber->resume($yielded);
                        
                        // If the fiber is still suspended, handle that
                        if ($this->fiber->isSuspended()) {
                            $this->handleSuspendedFiber();
                        }
                    } catch (\Throwable $e) {
                        $this->onException($e);
                    }
                });
            }
        } catch (\Throwable $e) {
            // Handle any exceptions that occur during suspension
            $this->onException($e);
        }
    }
    
    /**
     * Called when the handler completes successfully.
     *
     * @param mixed $result The result of the handler.
     */
    private function onResult(mixed $result): void
    {
        $this->deferred->resolve($result);
        
        $this->makeCurrent();
        $this->context->resolveConditions();
        
        // Call close handlers
        foreach ($this->onClose as $close) {
            $close($result);
        }
    }
    
    /**
     * Called when the handler throws an exception.
     *
     * @param \Throwable $e The exception thrown by the handler.
     */
    private function onException(\Throwable $e): void
    {
        // Add stack trace to temporal failures
        if ($e instanceof TemporalFailure && !$e->hasOriginalStackTrace()) {
            $e->setOriginalStackTrace($this->context->getStackTrace());
        }
        
        $this->deferred->reject($e);
        
        $this->makeCurrent();
        $this->context->resolveConditions();
        
        // Call close handlers
        foreach ($this->onClose as $close) {
            $close($e);
        }
    }
    
    /**
     * Defer execution of a function until the next tick.
     *
     * @param \Closure $tick The function to execute.
     */
    private function defer(\Closure $tick): void
    {
        $this->services->loop->once($this->layer, $tick);
        $this->services->queue->count() === 0 and $this->services->loop->tick();
    }
}