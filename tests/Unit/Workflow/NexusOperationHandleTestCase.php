<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Workflow;

use React\Promise\Deferred;
use PHPUnit\Framework\Attributes\CoversClass;
use Temporal\DataConverter\EncodedValues;
use Temporal\Internal\Workflow\Process\DeferredFiber;
use Temporal\Internal\Workflow\Process\FiberSuspension;
use Temporal\Tests\Unit\AbstractUnit;
use Temporal\Workflow\NexusOperationHandle;
use Temporal\Workflow\WorkflowContextInterface;

/**
 * @group unit
 * @group nexus
 */
#[CoversClass(NexusOperationHandle::class)]
final class NexusOperationHandleTestCase extends AbstractUnit
{
    public function testGetResultSuspendsTheWorkflowFiberAndReturnsDecodedValue(): void
    {
        $deferred = new Deferred();
        $handle = new NexusOperationHandle(
            operationToken: null,
            rawResult: $deferred->promise(),
        );

        $fiber = DeferredFiber::fromHandler(
            static fn() => $handle->getResult(),
            EncodedValues::empty(),
            $this->createStub(WorkflowContextInterface::class),
        );
        $suspended = $fiber->start();
        self::assertInstanceOf(FiberSuspension::class, $suspended);
        self::assertSame($handle->getResultAsync(), $suspended->promise);
        self::assertFalse($suspended->interruptOnCancel);
        self::assertTrue($suspended->preserveCancellationFailure);

        // A non-Values resolution flows through decodePromise unchanged.
        $deferred->resolve('hello');
        self::assertNull($fiber->resume('hello'));
        self::assertSame('hello', $fiber->getReturn());
    }

    public function testGetResultAsyncIsIdempotent(): void
    {
        $handle = new NexusOperationHandle(
            operationToken: null,
            rawResult: (new Deferred())->promise(),
        );

        // Multiple calls must return the same promise — callers may attach
        // handlers at different points in the workflow without spawning
        // duplicate operations.
        self::assertSame($handle->getResultAsync(), $handle->getResultAsync());
    }

    public function testTokenAvailableBeforeResultResolves(): void
    {
        // The handle is fully populated by the time the caller has it: token
        // is observable while the result-promise is still pending. Workflow
        // code can capture the token and pass it elsewhere before yielding.
        $deferred = new Deferred();
        $handle = new NexusOperationHandle(
            operationToken: 'observed-while-pending',
            rawResult: $deferred->promise(),
        );

        self::assertSame('observed-while-pending', $handle->getOperationToken());

        $resolved = false;
        $handle->getResultAsync()->then(static function () use (&$resolved): void {
            $resolved = true;
        });
        self::assertFalse($resolved);
    }

    public function testGetOperationTokenReturnsNullForSyncOperation(): void
    {
        $handle = new NexusOperationHandle(
            operationToken: null,
            rawResult: (new Deferred())->promise(),
        );

        self::assertNull($handle->getOperationToken());
    }

    public function testGetOperationTokenReturnsTokenForAsyncOperation(): void
    {
        $handle = new NexusOperationHandle(
            operationToken: 'op-token-xyz',
            rawResult: (new Deferred())->promise(),
        );

        self::assertSame('op-token-xyz', $handle->getOperationToken());
    }
}
