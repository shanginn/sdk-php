<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Nexus\Unit\Handler;

use PHPUnit\Framework\Attributes\DataProvider;
use Temporal\Exception\TransportException;
use Temporal\Nexus\Handler\ClosureMethodCancellationListener;
use Temporal\Nexus\Handler\MethodCanceller;
use Temporal\Nexus\Handler\MethodCancellationListenerInterface;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\Environment\EnvironmentInterface;
use Temporal\Worker\Transport\Command\Server\TickInfo;
use Temporal\Worker\Transport\RPCConnectionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodCanceller::class)]
final class MethodCancellerTest extends TestCase
{
    private EnvironmentInterface $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new Environment();
    }

    public function testNotCancelledByDefault(): void
    {
        $canceller = new MethodCanceller($this->env);

        self::assertFalse($canceller->isCancelled());
        self::assertNull($canceller->getReason());
    }

    public function testCancelSetsReason(): void
    {
        $canceller = new MethodCanceller($this->env);
        $canceller->cancel('deadline exceeded');

        self::assertTrue($canceller->isCancelled());
        self::assertSame('deadline exceeded', $canceller->getReason());
    }

    public function testCancelIsIdempotent(): void
    {
        $canceller = new MethodCanceller($this->env);
        $canceller->cancel('first');
        $canceller->cancel('second');

        self::assertSame('first', $canceller->getReason(), 'second cancel must be a no-op');
    }

    public function testListenerInvokedOnCancel(): void
    {
        $canceller = new MethodCanceller($this->env);
        $hits = 0;
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$hits): void {
                $hits++;
            },
        ));

        $canceller->cancel('shutdown');

        self::assertSame(1, $hits);
        // Listeners must read the reason from the canceller if they need it.
        self::assertSame('shutdown', $canceller->getReason());
    }

    public function testListenerInvokedOnlyOnceAcrossDuplicateCancels(): void
    {
        $canceller = new MethodCanceller($this->env);
        $count = 0;
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$count): void {
                $count++;
            },
        ));

        $canceller->cancel('first');
        $canceller->cancel('second');

        self::assertSame(1, $count);
    }

    public function testListenerAddedAfterCancelInvokedImmediately(): void
    {
        $canceller = new MethodCanceller($this->env);
        $canceller->cancel('gone');

        $fired = false;
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$fired): void {
                $fired = true;
            },
        ));

        self::assertTrue($fired);
    }

    public function testRemovedListenerNotInvoked(): void
    {
        $canceller = new MethodCanceller($this->env);
        $invoked = false;

        $listener = new class($invoked) implements MethodCancellationListenerInterface {
            public function __construct(private bool &$invoked) {}

            public function cancelled(): void
            {
                $this->invoked = true;
            }
        };

        $canceller->addListener($listener);
        $canceller->removeListener($listener);
        $canceller->cancel('irrelevant');

        self::assertFalse($invoked);
    }

    public function testDeadlineNotExpiredYet(): void
    {
        $canceller = new MethodCanceller($this->env, new \DateTimeImmutable('+1 hour'));

        self::assertFalse($canceller->isCancelled());
        self::assertNull($canceller->getReason());
    }

    public function testExpiredDeadlineAutoCancelsOnIsCancelled(): void
    {
        $canceller = new MethodCanceller($this->env, new \DateTimeImmutable('-1 second'));

        self::assertTrue($canceller->isCancelled());
        self::assertStringContainsString('deadline exceeded', (string) $canceller->getReason());
    }

    public function testExpiredDeadlineAutoCancelsOnGetReason(): void
    {
        $canceller = new MethodCanceller($this->env, new \DateTimeImmutable('-1 second'));

        // getReason() must trip the cancellation even if isCancelled() wasn't called first.
        self::assertNotNull($canceller->getReason());
        self::assertTrue($canceller->isCancelled());
    }

    public function testListenerFiresOnDeadlineTrip(): void
    {
        $this->env->update(new TickInfo(time: new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $deadline = new \DateTimeImmutable('2026-01-01T00:00:00.100Z');
        $canceller = new MethodCanceller($this->env, $deadline);

        $fired = false;
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$fired): void {
                $fired = true;
            },
        ));
        self::assertFalse($canceller->isCancelled());
        self::assertFalse($fired);

        $this->env->update(new TickInfo(time: new \DateTimeImmutable('2026-01-01T00:00:01Z')));
        self::assertTrue($canceller->isCancelled());

        self::assertTrue($fired);
        self::assertStringContainsString('deadline exceeded', (string) $canceller->getReason());
    }

    public function testExplicitCancelWinsOverDeadline(): void
    {
        $canceller = new MethodCanceller($this->env, new \DateTimeImmutable('-1 second'));

        $canceller->cancel('shutdown');

        // Explicit cancel() before any deadline inspection wins; lazy deadline check is a no-op afterwards.
        self::assertSame('shutdown', $canceller->getReason());
    }

    public function testAddListenerOnAlreadyExpiredDeadlineInvokesImmediately(): void
    {
        $canceller = new MethodCanceller($this->env, new \DateTimeImmutable('-1 second'));

        $fired = false;
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$fired): void {
                $fired = true;
            },
        ));

        self::assertTrue($fired, 'listener must fire synchronously when deadline already passed');
        self::assertStringContainsString('deadline exceeded', (string) $canceller->getReason());
    }

    public function testNoDeadlineNeverAutoCancels(): void
    {
        $canceller = new MethodCanceller($this->env);

        self::assertFalse($canceller->isCancelled());
    }

    public function testListenersInvokedInRegistrationOrder(): void
    {
        $canceller = new MethodCanceller($this->env);
        $order = [];
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$order): void {
                $order[] = 'a';
            },
        ));
        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$order): void {
                $order[] = 'b';
            },
        ));

        $canceller->cancel('x');

        self::assertSame(['a', 'b'], $order);
    }

    public function testPollsRoadRunnerUntilCancellationIsObservedAndThenCachesIt(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc
            ->expects(self::exactly(2))
            ->method('call')
            ->with('temporal.GetNexusMethodCancellation', ['invocationId' => 42])
            ->willReturnOnConsecutiveCalls(
                ['cancelled' => false],
                ['cancelled' => true, 'reason' => 'client disconnected'],
            );

        $canceller = new MethodCanceller($this->env, rpc: $rpc, invocationId: 42);

        self::assertFalse($canceller->isCancelled());
        self::assertSame('client disconnected', $canceller->getReason());
        self::assertTrue($canceller->isCancelled(), 'observed cancellation must be cached without another RPC');
        self::assertSame('client disconnected', $canceller->getReason());
    }

    public function testListenerRegistrationPollsAndLaterInspectionNotifiesItOnce(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc
            ->expects(self::exactly(2))
            ->method('call')
            ->with('temporal.GetNexusMethodCancellation', ['invocationId' => 7])
            ->willReturnOnConsecutiveCalls(
                ['cancelled' => false],
                ['cancelled' => true, 'reason' => 'request cancelled'],
            );

        $canceller = new MethodCanceller($this->env, rpc: $rpc, invocationId: 7);
        $listener = new class implements MethodCancellationListenerInterface {
            public int $hits = 0;

            public function cancelled(): void
            {
                $this->hits++;
            }
        };
        $canceller->addListener($listener);

        self::assertSame(0, $listener->hits, 'registration only performs one poll; it does not create a watcher');
        self::assertTrue($canceller->isCancelled());
        self::assertSame(1, $listener->hits);

        self::assertTrue($canceller->isCancelled());
        self::assertSame(1, $listener->hits);
    }

    public function testListenerAddedWhenRpcAlreadyReportsCancellationRunsSynchronously(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc
            ->expects(self::once())
            ->method('call')
            ->with('temporal.GetNexusMethodCancellation', ['invocationId' => 9])
            ->willReturn(['cancelled' => true]);

        $canceller = new MethodCanceller($this->env, rpc: $rpc, invocationId: 9);
        $called = false;

        $canceller->addListener(ClosureMethodCancellationListener::fromCallable(
            static function () use (&$called): void {
                $called = true;
            },
        ));

        self::assertTrue($called);
        self::assertTrue($canceller->isCancelled());
        self::assertSame('', $canceller->getReason(), 'an omitted remote reason remains an observed cancellation');
    }

    public function testLocalCancellationWinsWithoutPollingRoadRunner(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc->expects(self::never())->method('call');

        $canceller = new MethodCanceller($this->env, rpc: $rpc, invocationId: 11);
        $canceller->cancel('legacy local route');

        self::assertTrue($canceller->isCancelled());
        self::assertSame('legacy local route', $canceller->getReason());
    }

    public function testExpiredDeadlineWinsWithoutPollingRoadRunner(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc->expects(self::never())->method('call');

        $canceller = new MethodCanceller(
            $this->env,
            new \DateTimeImmutable('-1 second'),
            $rpc,
            12,
        );

        self::assertTrue($canceller->isCancelled());
        self::assertStringContainsString('deadline exceeded', (string) $canceller->getReason());
    }

    public function testZeroInvocationIdDisablesRemotePolling(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc->expects(self::never())->method('call');

        $canceller = new MethodCanceller($this->env, rpc: $rpc);

        self::assertFalse($canceller->isCancelled());
        self::assertNull($canceller->getReason());
    }

    public function testTransportFailureIsNotMisreportedAsNotCancelled(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc
            ->expects(self::once())
            ->method('call')
            ->willThrowException(new TransportException('RoadRunner RPC is unavailable'));

        $canceller = new MethodCanceller($this->env, rpc: $rpc, invocationId: 13);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('RoadRunner RPC is unavailable');

        $canceller->isCancelled();
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedRpcResponseProvider(): iterable
    {
        yield 'not an array' => [null, 'expected array, got null'];
        yield 'missing cancelled' => [[], 'required "cancelled" field is missing'];
        yield 'cancelled is not boolean' => [['cancelled' => 1], '"cancelled" must be bool, got int'];
        yield 'present reason is not string' => [
            ['cancelled' => false, 'reason' => null],
            '"reason" must be string when present, got null',
        ];
    }

    #[DataProvider('malformedRpcResponseProvider')]
    public function testMalformedRpcResponseFailsClearly(mixed $response, string $expectedMessage): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc
            ->expects(self::once())
            ->method('call')
            ->willReturn($response);

        $canceller = new MethodCanceller($this->env, rpc: $rpc, invocationId: 13);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Malformed temporal.GetNexusMethodCancellation RPC response');
        $this->expectExceptionMessage($expectedMessage);

        $canceller->isCancelled();
    }
}
