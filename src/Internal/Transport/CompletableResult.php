<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Transport;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Temporal\Internal\Promise\DeferredPromise;
use Temporal\Promise;
use Temporal\Worker\LoopInterface;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;

/**
 * @template T of mixed
 * @implements CompletableResultInterface<T>
 */
class CompletableResult implements CompletableResultInterface
{
    private bool $resolved = false;

    /**
     * @var mixed
     */
    private $value;

    private WorkflowContextInterface $context;
    private LoopInterface $loop;
    private Future $future;
    private DeferredPromise $deferred;
    private string $layer;

    /**
     * CompletableResult constructor.
     */
    public function __construct(
        WorkflowContextInterface $context,
        LoopInterface $loop,
        Future $future,
        string $layer,
    ) {
        $this->context = $context;
        $this->loop = $loop;
        $this->deferred = new DeferredPromise();
        $this->layer = $layer;

        $this->future = $future
            ->map(fn($value) => $this->onFulfilled($value))
            ->catch(fn(\Throwable $e) => $this->onRejected($e))
            ->ignore();
    }

    public function isComplete(): bool
    {
        return $this->resolved;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param (callable(mixed): mixed)|null $onFulfilled
     * @param (callable(\Throwable): mixed)|null $onRejected
     * @return Promise<mixed>
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null,
        ?callable $onProgress = null,
    ): Promise {
        return $this->promise()
            ->then($this->wrapContext($onFulfilled), $this->wrapContext($onRejected));
    }

    /**
     * @return Promise<T>
     */
    public function promise(): Promise
    {
        return $this->deferred->promise();
    }

    /**
     * @param callable(\Throwable): mixed $onRejected
     * @return Promise<mixed>
     */
    public function catch(callable $onRejected): Promise
    {
        return $this->promise()
            ->catch($this->wrapContext($onRejected));
    }

    /**
     * @param callable(): void $onFulfilledOrRejected
     * @return Promise<mixed>
     */
    public function finally(callable $onFulfilledOrRejected): Promise
    {
        return $this->promise()
            ->finally($this->wrapContext($onFulfilledOrRejected));
    }

    /**
     * Cancels the underlying operation if possible.
     */
    public function cancel(): void
    {
        // For now, we don't have a direct cancellation mechanism
        // but we'll mark the operation as rejected with cancellation
        if (!$this->resolved) {
            $this->resolved = true;
            $this->loop->once(
                $this->layer,
                function (): void {
                    Workflow::setCurrentContext($this->context);
                    $this->deferred->reject(new \Temporal\Exception\Failure\CanceledFailure('Operation cancelled'));
                },
            );
        }
    }

    /**
     * @deprecated Use catch() instead
     * @param callable(\Throwable): mixed $onRejected
     * @return Promise<mixed>
     */
    public function otherwise(callable $onRejected): Promise
    {
        return $this->catch($this->wrapContext($onRejected));
    }

    /**
     * @deprecated Use finally() instead
     * @param callable(): void $onFulfilledOrRejected
     * @return Promise<mixed>
     */
    public function always(callable $onFulfilledOrRejected): Promise
    {
        return $this->finally($this->wrapContext($onFulfilledOrRejected));
    }

    /**
     * @return Future<mixed>
     */
    public function getFuture(): Future
    {
        return $this->deferred->promise();
    }

    /**
     * @param T $result
     * @return T
     */
    private function onFulfilled(mixed $result): mixed
    {
        $this->resolved = true;
        $this->value = $result;

        $this->loop->once(
            $this->layer,
            function (): void {
                Workflow::setCurrentContext($this->context);
                $this->deferred->resolve($this->value);
            },
        );
        
        return $result;
    }

    /**
     * @throws \Throwable
     */
    private function onRejected(\Throwable $e): never
    {
        $this->resolved = true;

        $this->loop->once(
            $this->layer,
            function () use ($e): void {
                Workflow::setCurrentContext($this->context);
                $this->deferred->reject($e);
            },
        );
        
        throw $e;
    }

    /**
     * @template TParam of mixed
     * @template TReturn of mixed
     * @param (callable(TParam): TReturn)|null $callback
     * @return ($callback is null ? null : (callable(TParam): TReturn))
     */
    private function wrapContext(?callable $callback): ?callable
    {
        if ($callback === null) {
            return null;
        }

        return function (mixed $value = null) use ($callback): mixed {
            Workflow::setCurrentContext($this->context);
            return $callback($value);
        };
    }
}