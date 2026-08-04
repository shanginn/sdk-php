<?php

declare(strict_types=1);

namespace Temporal\Internal\Workflow\Process;

use React\Promise\PromiseInterface;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Internal\Declaration\MethodHandler;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;

/**
 * Lazily starts a workflow handler inside a managed Fiber.
 *
 * @internal
 * @psalm-internal Temporal
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class DeferredFiber
{
    private \Fiber $fiber;

    /** @var array<\Closure(\Throwable): mixed> */
    private array $catchers = [];

    private function __construct() {}

    /**
     * @param MethodHandler|\Closure(ValuesInterface): mixed $handler
     */
    public static function fromHandler(
        MethodHandler|\Closure $handler,
        ValuesInterface $values,
        WorkflowContextInterface $context,
    ): self {
        $self = new self();
        $self->fiber = new \Fiber(static function () use ($handler, $values, $context): mixed {
            Workflow::setCurrentContext($context);
            try {
                $result = $handler($values);

                if ($result instanceof \Generator) {
                    throw new \LogicException(
                        'Generator workflow handlers are not supported; call Temporal workflow APIs directly.',
                    );
                }

                if ($result instanceof PromiseInterface) {
                    throw new \LogicException(
                        'Promise-returning workflow handlers are not supported; '
                        . 'call direct workflow APIs or await an async scope explicitly.',
                    );
                }

                return $result;
            } finally {
                Workflow::setCurrentContext(null);
            }
        });
        return $self;
    }

    /**
     * Start the handler and return the first value suspended by {@see Awaiter}.
     */
    public function start(): mixed
    {
        $this->fiber->isStarted() and throw new \LogicException('Cannot start a workflow Fiber more than once.');

        Awaiter::register($this->fiber);

        try {
            return $this->fiber->start();
        } catch (\Throwable $e) {
            $this->handleException($e);
        } finally {
            $this->unregisterIfTerminated();
        }
    }

    /**
     * Resume the handler with a fulfilled promise value.
     */
    public function resume(mixed $value): mixed
    {
        !$this->fiber->isStarted() and throw new \LogicException('Cannot resume a workflow Fiber before it starts.');
        !$this->fiber->isSuspended() and throw new \LogicException('Cannot resume a workflow Fiber that is not suspended.');

        try {
            return $this->fiber->resume($value);
        } catch (\Throwable $e) {
            $this->handleException($e);
        } finally {
            $this->unregisterIfTerminated();
        }
    }

    /**
     * Throw a rejected promise error at the suspended await call site.
     */
    public function throw(\Throwable $exception): mixed
    {
        !$this->fiber->isStarted() and throw new \LogicException(
            'Cannot throw an exception into a workflow Fiber before it starts.',
        );
        !$this->fiber->isSuspended() and throw new \LogicException(
            'Cannot throw an exception into a workflow Fiber that is not suspended.',
        );

        try {
            return $this->fiber->throw($exception);
        } catch (\Throwable $e) {
            $this->handleException($e);
        } finally {
            $this->unregisterIfTerminated();
        }
    }

    public function isStarted(): bool
    {
        return $this->fiber->isStarted();
    }

    public function isSuspended(): bool
    {
        return $this->fiber->isSuspended();
    }

    public function isTerminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    /**
     * Return the handler result after the Fiber terminates successfully.
     */
    public function getReturn(): mixed
    {
        !$this->fiber->isTerminated() and throw new \LogicException(
            'Cannot get the return value of a workflow Fiber that has not terminated.',
        );

        return $this->fiber->getReturn();
    }

    /**
     * Add an exception handler.
     *
     * @param \Closure(\Throwable): mixed $handler
     */
    public function catch(callable $handler): self
    {
        $this->catchers[] = $handler;
        return $this;
    }

    private function unregisterIfTerminated(): void
    {
        if ($this->fiber->isTerminated()) {
            Awaiter::unregister($this->fiber);
        }
    }

    private function handleException(\Throwable $e): never
    {
        foreach ($this->catchers as $catcher) {
            try {
                $catcher($e);
            } catch (\Throwable) {
                // Keep the original workflow failure.
            }
        }

        $this->catchers = [];
        throw $e;
    }
}
