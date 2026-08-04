<?php

declare(strict_types=1);

namespace Temporal\Internal\Workflow\Process;

use React\Promise\PromiseInterface;
use Temporal\Workflow;

/**
 * Bridges React promises to workflow Fibers managed by {@see Scope}.
 *
 * @internal
 * @psalm-internal Temporal
 */
final class Awaiter
{
    /** @var \WeakMap<\Fiber, true>|null */
    private static ?\WeakMap $managedFibers = null;

    private function __construct() {}

    /**
     * Suspend the current managed workflow Fiber until the promise settles.
     *
     * @template T
     * @param PromiseInterface<T> $promise
     * @return T
     */
    public static function await(
        PromiseInterface $promise,
        bool $interruptOnCancel = true,
        bool $preserveCancellationFailure = false,
    ): mixed {
        self::getManagedFiber();

        $context = Workflow::getCurrentContext();

        try {
            /** @var T $result */
            $result = \Fiber::suspend(new FiberSuspension(
                $promise,
                $interruptOnCancel,
                $preserveCancellationFailure,
            ));
            return $result;
        } finally {
            // Promise callbacks run in the activation coroutine and may bind
            // their own scope context. Always restore the context belonging to
            // this workflow Fiber before application code continues.
            Workflow::setCurrentContext($context);
        }
    }

    /**
     * Fail before a direct workflow API constructs or queues an operation when
     * it is called from an arbitrary promise callback or another unmanaged
     * execution context.
     */
    public static function assertManaged(): void
    {
        self::getManagedFiber();
    }

    /**
     * @internal Scope engine API.
     */
    public static function register(\Fiber $fiber): void
    {
        if (self::$managedFibers === null) {
            /** @var \WeakMap<\Fiber, true> $fibers */
            $fibers = new \WeakMap();
            $fibers[$fiber] = true;
            self::$managedFibers = $fibers;
            return;
        }

        self::$managedFibers[$fiber] = true;
    }

    /**
     * @internal Scope engine API.
     */
    public static function unregister(\Fiber $fiber): void
    {
        if (self::$managedFibers !== null) {
            unset(self::$managedFibers[$fiber]);
        }
    }

    private static function getManagedFiber(): \Fiber
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null || self::$managedFibers === null || !isset(self::$managedFibers[$fiber])) {
            // Preserve the facade's public out-of-workflow error when no
            // Workflow context exists at all. A bound context without a
            // managed Fiber is the separate promise-callback misuse case.
            Workflow::getCurrentContext();
            throw new \LogicException(
                'Temporal promises can only be awaited inside a managed workflow Fiber.',
            );
        }

        return $fiber;
    }
}
