<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Throwable;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Internal\Promise\CompositeException;

/**
 * @psalm-type PromiseMapCallback = callable(mixed $value): mixed
 * @psalm-type PromiseReduceCallback = callable(mixed $value): mixed
 * 
 * @template-covariant T
 * @extends Future<T>
 */
final class Promise extends Future
{
    /**
     * Returns a future that resolves when all items in `$promises` have resolved.
     *
     * The returned future's resolution value will be an array containing
     * the resolution values of each item in `$promises`.
     *
     * @template TValue
     * @param iterable<int, Promise<TValue>|Future<TValue>|mixed> $promises
     * @return Promise<array<TValue>>
     */
    public static function all(iterable $promises): Promise
    {
        return self::map($promises, static fn($val): mixed => $val);
    }

    /**
     * Returns a future that resolves when any item in `$promises` resolves.
     *
     * The returned future's resolution value will be the resolution value of
     * the triggering item. If **all** items in `$promises` are rejected, the
     * returned future will reject with a CompositeException containing all rejection reasons.
     *
     * @template TValue
     * @param iterable<int, Promise<TValue>|Future<TValue>|mixed> $promises
     * @return Promise<TValue>
     */
    public static function any(iterable $promises): Promise
    {
        return self::some([...$promises], 1)
            ->map(static fn(array $values): mixed => \array_shift($values));
    }

    /**
     * Returns a future that resolves when `$count` items in `$promises` resolve.
     *
     * The returned future's resolution value will be an array of length `$count`
     * containing the resolution values of the triggering items.
     *
     * If it becomes impossible for `$count` items to resolve (i.e., when
     * `(count($promises) - $count) + 1` items reject), the returned future will
     * reject with a CompositeException containing `(count($promises) - $howMany) + 1`
     * rejection reasons.
     *
     * @template TValue
     * @param array<int, Promise<TValue>|Future<TValue>|mixed> $promises
     * @return Promise<array<TValue>>
     */
    public static function some(array $promises, int $count): Promise
    {
        if ($count < 1) {
            return self::complete([]);
        }

        $len = \count($promises);

        if ($len < $count) {
            return self::error(new \LengthException(
                \sprintf(
                    'Input array must contain at least %d item%s but contains only %s item%s.',
                    $count,
                    $count === 1 ? '' : 's',
                    $len,
                    $len === 1 ? '' : 's',
                ),
            ));
        }

        // Convert all promises to Futures
        $futures = [];
        foreach ($promises as $i => $promiseOrValue) {
            $futures[$i] = self::adapt($promiseOrValue);
        }

        $deferred = new DeferredFuture();
        $successCount = 0;
        $failureCount = 0;
        $neededFailures = ($len - $count) + 1;
        $values = [];
        $reasons = [];

        // Create a callback for each Future
        foreach ($futures as $i => $future) {
            $future
                ->map(function($value) use ($i, &$values, &$successCount, $count, $deferred) {
                    if ($deferred->isComplete()) {
                        return;
                    }

                    $values[$i] = $value;
                    $successCount++;

                    if ($successCount === $count) {
                        // Sort by original key to maintain order
                        \ksort($values);
                        $deferred->complete(\array_values($values));
                    }
                    
                    return $value;
                })
                ->catch(function(Throwable $reason) use ($i, &$reasons, &$failureCount, $neededFailures, $len, $deferred) {
                    if ($deferred->isComplete()) {
                        return;
                    }

                    $reasons[$i] = $reason;
                    $failureCount++;

                    if ($failureCount === $neededFailures) {
                        $deferred->error(new CompositeException($reasons));
                    }
                    
                    throw $reason;
                })
                ->ignore();
        }

        return new self($deferred->getFuture()->state);
    }

    /**
     * Applies a callback function to each item in `$promises`.
     *
     * This function behaves like `array_map()`, but it can handle input containing
     * promises and/or values, and the `$callback` may return either a value or a promise.
     *
     * @template TInput
     * @template TOutput
     * @psalm-param callable(TInput): TOutput $map
     * @param iterable<int, Promise<TInput>|Future<TInput>|TInput> $promises
     * @return Promise<array<TOutput>>
     */
    public static function map(iterable $promises, callable $map): Promise
    {
        $array = [];
        foreach ($promises as $i => $promiseOrValue) {
            $array[$i] = $promiseOrValue;
        }

        if (empty($array)) {
            return self::complete([]);
        }

        // Convert all values to Futures first
        $toResolve = \count($array);
        $values = [];

        $deferred = new DeferredFuture();

        foreach ($array as $i => $promiseOrValue) {
            $future = self::adapt($promiseOrValue);
            
            $future
                ->map(function ($value) use ($map, $i, &$values, &$toResolve, $deferred) {
                    try {
                        $mapped = $map($value);
                        $values[$i] = $mapped;

                        if (--$toResolve === 0) {
                            \ksort($values);
                            $deferred->complete($values);
                        }
                        
                        return $mapped;
                    } catch (Throwable $e) {
                        $deferred->error($e);
                        throw $e;
                    }
                })
                ->catch(function (Throwable $reason) use ($deferred) {
                    $deferred->error($reason);
                    throw $reason;
                })
                ->ignore();
        }

        return new self($deferred->getFuture()->state);
    }

    /**
     * Applies a callback function to each item in `$promises`, reducing them to a single value.
     *
     * This function behaves like `array_reduce()`, but it can handle input containing
     * promises and/or values. The `$reduce` callback may return either a value or a promise,
     * and `$initial` may be a promise or a value for the starting value.
     *
     * @template TInput
     * @template TOutput
     * @psalm-param callable(TOutput, TInput, int, positive-int): TOutput $reduce
     * @param iterable<int, Promise<TInput>|Future<TInput>|TInput> $promises
     * @param TOutput $initial
     * @return Promise<TOutput>
     */
    public static function reduce(iterable $promises, callable $reduce, $initial = null): Promise
    {
        $array = [];
        foreach ($promises as $promiseOrValue) {
            $array[] = $promiseOrValue;
        }

        if (empty($array)) {
            return self::adapt($initial);
        }

        $deferred = new DeferredFuture();
        $total = \count($array);
        $i = 0;

        $initialFuture = self::adapt($initial);
        $next = function ($carry) use (&$next, &$i, $array, $total, $reduce, $deferred) {
            if ($i >= $total) {
                $deferred->complete($carry);
                return;
            }

            $index = $i++;
            $item = $array[$index];
            $future = self::adapt($item);

            $future
                ->map(function ($value) use ($carry, $index, $total, $reduce, $deferred, $next) {
                    try {
                        $result = $reduce($carry, $value, $index, $total);
                        $resultFuture = self::adapt($result);
                        
                        $resultFuture
                            ->map(function ($reduced) use ($next) {
                                $next($reduced);
                                return $reduced;
                            })
                            ->catch(function (Throwable $reason) use ($deferred) {
                                $deferred->error($reason);
                                throw $reason;
                            })
                            ->ignore();
                            
                        return $value;
                    } catch (Throwable $e) {
                        $deferred->error($e);
                        throw $e;
                    }
                })
                ->catch(function (Throwable $reason) use ($deferred) {
                    $deferred->error($reason);
                    throw $reason;
                })
                ->ignore();
        };

        $initialFuture
            ->map(function ($value) use ($next) {
                $next($value);
                return $value;
            })
            ->catch(function (Throwable $reason) use ($deferred) {
                $deferred->error($reason);
                throw $reason;
            })
            ->ignore();

        return new self($deferred->getFuture()->state);
    }

    /**
     * Creates a completed future for the supplied `$promiseOrValue`.
     *
     * If `$promiseOrValue` is a value, it will be the resolution value of the
     * returned promise.
     *
     * If `$promiseOrValue` is a Future, it will be returned as is.
     *
     * @template TValue
     * @param Promise<TValue>|Future<TValue>|TValue|null $promiseOrValue
     * @return Promise<TValue>
     */
    public static function resolve($promiseOrValue = null): Promise
    {
        return self::adapt($promiseOrValue);
    }

    /**
     * Creates a rejected future for the supplied `$promiseOrValue`.
     *
     * If `$promiseOrValue` is a value, it will be the rejection value of the
     * returned promise.
     *
     * If `$promiseOrValue` is a promise, its completion value will be the rejected
     * value of the returned promise.
     *
     * @template TValue
     * @param Promise<TValue>|Future<TValue>|TValue|null $promiseOrValue
     * @return Promise<never>
     */
    public static function reject($promiseOrValue = null): Promise
    {
        if ($promiseOrValue instanceof Throwable) {
            return new self(Future::error($promiseOrValue)->state);
        }

        if ($promiseOrValue instanceof Future) {
            $deferred = new DeferredFuture();
            
            $promiseOrValue
                ->map(function ($value) use ($deferred) {
                    $deferred->error(new CanceledFailure(\is_object($value) 
                        ? \get_class($value) 
                        : \gettype($value)
                    ));
                    return $value;
                })
                ->catch(function (Throwable $reason) use ($deferred) {
                    $deferred->error($reason);
                    throw $reason;
                })
                ->ignore();
                
            return new self($deferred->getFuture()->state);
        }

        return new self(Future::error(new CanceledFailure(\is_object($promiseOrValue) 
            ? \get_class($promiseOrValue) 
            : (string)$promiseOrValue
        ))->state);
    }

    /**
     * Initiates a competitive race that allows one winner. Returns a promise which is
     * resolved in the same way the first settled promise resolves.
     *
     * @template TValue
     * @param iterable<Promise<TValue>|Future<TValue>|TValue> $promisesOrValues
     * @return Promise<TValue>
     */
    public static function race(iterable $promisesOrValues): Promise
    {
        $futures = [];
        foreach ($promisesOrValues as $promiseOrValue) {
            $futures[] = self::adapt($promiseOrValue);
        }

        if (empty($futures)) {
            $deferred = new DeferredFuture();
            // Never settle
            return new self($deferred->getFuture()->state);
        }

        $deferred = new DeferredFuture();
        
        foreach ($futures as $future) {
            $future
                ->map(function ($value) use ($deferred) {
                    if (!$deferred->isComplete()) {
                        $deferred->complete($value);
                    }
                    return $value;
                })
                ->catch(function (Throwable $reason) use ($deferred) {
                    if (!$deferred->isComplete()) {
                        $deferred->error($reason);
                    }
                    throw $reason;
                })
                ->ignore();
        }

        return new self($deferred->getFuture()->state);
    }

    /**
     * Converts any value to a Future
     *
     * @template TValue
     * @param Promise<TValue>|Future<TValue>|TValue $value
     * @return Future<TValue>
     */
    private static function adapt($value): Future
    {
        if ($value instanceof Future) {
            return $value;
        }

        return Future::complete($value);
    }

    /**
     * Creates a promise that resolves from a cancellable operation.
     * 
     * @param callable(Cancellation): mixed $operation
     * @param Cancellation|null $cancellation
     * @return Promise<mixed>
     */
    public static function fromCancellable(callable $operation, ?Cancellation $cancellation = null): Promise
    {
        $deferred = new DeferredFuture();
        
        try {
            $result = $operation($cancellation ?? new \Amp\NullCancellation());
            $deferred->complete($result);
        } catch (Throwable $e) {
            $deferred->error($e);
        }
        
        return new self($deferred->getFuture()->state);
    }
    
    /**
     * Support for ReactPHP Promise API compatibility
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     * @param callable|null $onProgress - Ignored, only for compatibility
     * @return Promise<mixed>
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null,
        ?callable $onProgress = null
    ): Promise {
        $deferred = new DeferredFuture();
        
        $this
            ->map(function ($value) use ($onFulfilled, $deferred) {
                try {
                    if ($onFulfilled === null) {
                        $deferred->complete($value);
                        return $value;
                    }
                    
                    $result = $onFulfilled($value);
                    if ($result instanceof Future) {
                        $result
                            ->map(function ($mappedValue) use ($deferred) {
                                $deferred->complete($mappedValue);
                                return $mappedValue;
                            })
                            ->catch(function (Throwable $reason) use ($deferred) {
                                $deferred->error($reason);
                                throw $reason;
                            })
                            ->ignore();
                    } else {
                        $deferred->complete($result);
                    }
                    
                    return $value;
                } catch (Throwable $e) {
                    $deferred->error($e);
                    throw $e;
                }
            })
            ->catch(function (Throwable $reason) use ($onRejected, $deferred) {
                try {
                    if ($onRejected === null) {
                        $deferred->error($reason);
                        throw $reason;
                    }
                    
                    $result = $onRejected($reason);
                    if ($result instanceof Future) {
                        $result
                            ->map(function ($mappedValue) use ($deferred) {
                                $deferred->complete($mappedValue);
                                return $mappedValue;
                            })
                            ->catch(function (Throwable $innerReason) use ($deferred) {
                                $deferred->error($innerReason);
                                throw $innerReason;
                            })
                            ->ignore();
                    } else {
                        $deferred->complete($result);
                    }
                } catch (Throwable $e) {
                    $deferred->error($e);
                    throw $e;
                }
                
                throw $reason;
            })
            ->ignore();
            
        return new self($deferred->getFuture()->state);
    }
    
    /**
     * Support for ReactPHP Promise API compatibility
     *
     * @param callable $onRejected
     * @return Promise<mixed>
     */
    public function catch(callable $onRejected): Promise
    {
        return $this->then(null, $onRejected);
    }
    
    /**
     * Support for ReactPHP Promise API compatibility (alias for catch)
     *
     * @param callable $onRejected
     * @return Promise<mixed>
     */
    public function otherwise(callable $onRejected): Promise
    {
        return $this->catch($onRejected);
    }
    
    /**
     * Support for ReactPHP Promise API compatibility
     *
     * @param callable $onFulfilledOrRejected
     * @return Promise<mixed>
     */
    public function finally(callable $onFulfilledOrRejected): Promise
    {
        $deferred = new DeferredFuture();
        
        $this
            ->map(function ($value) use ($onFulfilledOrRejected, $deferred) {
                try {
                    $onFulfilledOrRejected();
                    $deferred->complete($value);
                    return $value;
                } catch (Throwable $e) {
                    $deferred->error($e);
                    throw $e;
                }
            })
            ->catch(function (Throwable $reason) use ($onFulfilledOrRejected, $deferred) {
                try {
                    $onFulfilledOrRejected();
                    $deferred->error($reason);
                } catch (Throwable $e) {
                    $deferred->error($e);
                }
                
                throw $reason;
            })
            ->ignore();
            
        return new self($deferred->getFuture()->state);
    }
    
    /**
     * Support for ReactPHP Promise API compatibility (alias for finally)
     *
     * @param callable $onFulfilledOrRejected
     * @return Promise<mixed>
     */
    public function always(callable $onFulfilledOrRejected): Promise
    {
        return $this->finally($onFulfilledOrRejected);
    }
    
    /**
     * Adapts a ReactPHP Promise to Temporal Promise
     *
     * @param mixed $promise
     * @return Promise<mixed>
     */
    public static function fromReactPromise($promise): Promise
    {
        if ($promise instanceof Promise || $promise instanceof Future) {
            return $promise instanceof Promise ? $promise : new self($promise->state);
        }
        
        $deferred = new DeferredFuture();
        
        if (is_object($promise) && method_exists($promise, 'then')) {
            $promise->then(
                function ($value) use ($deferred) {
                    $deferred->complete($value);
                },
                function ($reason) use ($deferred) {
                    $deferred->error($reason instanceof Throwable ? $reason : new \Exception($reason));
                }
            );
        } else {
            $deferred->complete($promise);
        }
        
        return new self($deferred->getFuture()->state);
    }
}