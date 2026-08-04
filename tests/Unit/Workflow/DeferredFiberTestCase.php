<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use Temporal\DataConverter\EncodedValues;
use Temporal\Internal\Workflow\Process\Awaiter;
use Temporal\Internal\Workflow\Process\DeferredFiber;
use Temporal\Internal\Workflow\Process\FiberSuspension;
use Temporal\Promise;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;

final class DeferredFiberTestCase extends TestCase
{
    public function testHandlerStartsLazilyAndReturnsValue(): void
    {
        $called = false;
        $fiber = DeferredFiber::fromHandler(
            static function () use (&$called): int {
                $called = true;
                return 42;
            },
            EncodedValues::empty(),
            $this->context(),
        );

        self::assertFalse($called);
        self::assertFalse($fiber->isStarted());

        self::assertNull($fiber->start());
        self::assertTrue($called);
        self::assertTrue($fiber->isStarted());
        self::assertTrue($fiber->isTerminated());
        self::assertSame(42, $fiber->getReturn());
    }

    public function testAwaiterSuspendsAndResumeReturnsValueToHandler(): void
    {
        $deferred = new Deferred();
        $context = $this->context();
        $fiber = DeferredFiber::fromHandler(
            static function () use ($deferred, $context): string {
                self::assertSame($context, Workflow::getCurrentContext());
                $value = Awaiter::await($deferred->promise());
                self::assertSame($context, Workflow::getCurrentContext());

                return \strtoupper($value);
            },
            EncodedValues::empty(),
            $context,
        );

        $suspension = $fiber->start();
        self::assertInstanceOf(FiberSuspension::class, $suspension);
        self::assertSame($deferred->promise(), $suspension->promise);
        self::assertTrue($suspension->interruptOnCancel);
        self::assertFalse($suspension->preserveCancellationFailure);
        self::assertTrue($fiber->isSuspended());

        self::assertNull($fiber->resume('ready'));
        self::assertTrue($fiber->isTerminated());
        self::assertSame('READY', $fiber->getReturn());

        try {
            Workflow::getCurrentContext();
            self::fail('Workflow context was not cleared after Fiber termination.');
        } catch (\Temporal\Exception\OutOfContextException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRejectedPromiseErrorIsThrownAtAwaitCallSite(): void
    {
        $expected = new \RuntimeException('rejected');
        $fiber = DeferredFiber::fromHandler(
            static function (): string {
                try {
                    Awaiter::await(Promise::resolve(null));
                } catch (\RuntimeException $e) {
                    return $e->getMessage();
                }

                return 'not-thrown';
            },
            EncodedValues::empty(),
            $this->context(),
        );

        self::assertInstanceOf(FiberSuspension::class, $fiber->start());
        self::assertNull($fiber->throw($expected));
        self::assertSame('rejected', $fiber->getReturn());
    }

    public function testCatcherObservesUnhandledHandlerFailureOnce(): void
    {
        $expected = new \RuntimeException('failed');
        $caught = [];
        $fiber = DeferredFiber::fromHandler(
            static fn() => throw $expected,
            EncodedValues::empty(),
            $this->context(),
        )->catch(static function (\Throwable $e) use (&$caught): void {
            $caught[] = $e;
        });

        try {
            $fiber->start();
            self::fail('Expected handler failure.');
        } catch (\RuntimeException $e) {
            self::assertSame($expected, $e);
        }

        self::assertSame([$expected], $caught);
    }

    public function testGeneratorHandlerIsRejected(): void
    {
        $fiber = DeferredFiber::fromHandler(
            static fn(): \Generator => (static function (): \Generator {
                yield 1;
            })(),
            EncodedValues::empty(),
            $this->context(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Generator workflow handlers are not supported; call Temporal workflow APIs directly.',
        );

        $fiber->start();
    }

    public function testPromiseReturningHandlerIsRejected(): void
    {
        $fiber = DeferredFiber::fromHandler(
            static fn() => Promise::resolve(42),
            EncodedValues::empty(),
            $this->context(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Promise-returning workflow handlers are not supported; '
            . 'call direct workflow APIs or await an async scope explicitly.',
        );

        $fiber->start();
    }

    public function testGetReturnBeforeTerminationFails(): void
    {
        $fiber = DeferredFiber::fromHandler(
            static fn(): int => 42,
            EncodedValues::empty(),
            $this->context(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not terminated');

        $fiber->getReturn();
    }

    public function testResumeBeforeStartFails(): void
    {
        $fiber = DeferredFiber::fromHandler(
            static fn(): int => 42,
            EncodedValues::empty(),
            $this->context(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('before it starts');

        $fiber->resume(null);
    }

    public function testAwaiterRejectsCallsOutsideManagedWorkflowFiber(): void
    {
        Workflow::setCurrentContext($this->context());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('inside a managed workflow Fiber');

        Awaiter::await(Promise::resolve(null));
    }

    protected function tearDown(): void
    {
        Workflow::setCurrentContext(null);
    }

    private function context(): WorkflowContextInterface
    {
        return $this->createStub(WorkflowContextInterface::class);
    }
}
