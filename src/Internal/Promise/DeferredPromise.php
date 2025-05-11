<?php

declare(strict_types=1);

namespace Temporal\Internal\Promise;

use Amp\DeferredFuture;
use Temporal\Promise;

/**
 * @template T
 * @internal
 * @psalm-internal Temporal
 */
class DeferredPromise
{
    /**
     * @var DeferredFuture
     */
    private DeferredFuture $deferred;

    /**
     * @var Promise<T>
     */
    private Promise $promise;

    public function __construct()
    {
        $this->deferred = new DeferredFuture();
        $this->promise = new Promise($this->deferred->getFuture()->state);
    }

    /**
     * @return Promise<T>
     */
    public function promise(): Promise
    {
        return $this->promise;
    }

    /**
     * @param T $value
     */
    public function resolve($value = null): void
    {
        $this->deferred->complete($value);
    }

    /**
     * @param \Throwable $reason
     */
    public function reject(\Throwable $reason): void
    {
        $this->deferred->error($reason);
    }

    /**
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->deferred->isComplete();
    }
}