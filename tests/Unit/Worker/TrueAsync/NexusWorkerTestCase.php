<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\TrueAsync;

use Coresdk\Nexus\CancelNexusTask;
use Coresdk\Nexus\NexusTask;
use Coresdk\Nexus\NexusTaskCancelReason;
use Coresdk\Nexus\NexusTaskCompletion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Spiral\Attributes\AttributeReader;
use Temporal\Api\Nexus\V1\Request;
use Temporal\Api\Nexus\V1\StartOperationRequest;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Internal\Declaration\Prototype\NexusServiceCollection;
use Temporal\Internal\Declaration\Reader\NexusServiceReader;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Handler\ClosureMethodCancellationListener;
use Temporal\Nexus\Nexus;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\TrueAsync\NexusCoreWorkerInterface;
use Temporal\Worker\TrueAsync\NexusWorker;

#[Service(name: 'NativeNexusWorkerTestService')]
interface NativeNexusWorkerTestService
{
    #[Operation]
    public function wait(string $input): string;

    #[Operation]
    public function process(string $input): string;
}

final class NativeNexusWorkerTestServiceImpl implements NativeNexusWorkerTestService
{
    public bool $waiting = false;
    public ?string $cancellationReason = null;
    /** @var list<string> */
    public array $processedInputs = [];

    public function wait(string $input): string
    {
        $context = Nexus::getCurrentOperationContext();
        $context->addMethodCancellationListener(
            ClosureMethodCancellationListener::fromCallable(function () use ($context): void {
                $this->cancellationReason = $context->getMethodCancellationReason();
            }),
        );
        $this->waiting = true;

        \Async\delay(60_000);

        return $input;
    }

    public function process(string $input): string
    {
        $this->processedInputs[] = $input;

        if ($input === 'fail') {
            throw new \RuntimeException('handler implementation detail');
        }

        return \strtoupper($input);
    }
}

/**
 * Deterministic fake for the serialized Core Nexus worker boundary.
 */
final class FakeNexusCoreWorker implements NexusCoreWorkerInterface
{
    /** @var list<string> */
    public array $completions = [];

    public int $shutdownCount = 0;
    public ?\Closure $onShutdown = null;

    /** @var list<string|\Throwable|null> */
    private array $pollResults;

    public int $pollCount = 0;

    /**
     * @param list<string|\Throwable|null> $pollResults
     * @param array<int, \Closure(): void> $beforePoll
     */
    public function __construct(
        array $pollResults,
        private readonly array $beforePoll = [],
    ) {
        $this->pollResults = $pollResults;
    }

    public function pollNexusTask(): ?string
    {
        $index = $this->pollCount++;
        ($this->beforePoll[$index] ?? static function (): void {})();

        $result = $this->pollResults[$index] ?? null;
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function completeNexusTask(string $completion): void
    {
        $this->completions[] = $completion;
    }

    public function initiateShutdown(): void
    {
        ++$this->shutdownCount;
        ($this->onShutdown ?? static function (): void {})();
    }
}

/**
 * @group unit
 * @group nexus
 */
#[CoversClass(NexusWorker::class)]
final class NexusWorkerTestCase extends TestCase
{
    private DataConverterInterface $dataConverter;

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function cancellationReasonProvider(): iterable
    {
        yield 'request timed out' => [
            NexusTaskCancelReason::TIMED_OUT,
            'Nexus handler request timed out.',
        ];
        yield 'worker shutdown' => [
            NexusTaskCancelReason::WORKER_SHUTDOWN,
            'Nexus worker is shutting down.',
        ];
    }

    #[DataProvider('cancellationReasonProvider')]
    public function testInFlightCoreCancellationIsDeliveredAndAcknowledged(
        int $reason,
        string $expectedReason,
    ): void {
        $service = new NativeNexusWorkerTestServiceImpl();
        $token = "\x00\xff\x80nexus-task-token";
        $core = new FakeNexusCoreWorker(
            [
                $this->startTask($token, 'wait', 'parked'),
                self::cancelTask($token, $reason),
                null,
            ],
            [
                1 => static fn() => self::waitUntil(
                    static fn(): bool => $service->waiting,
                    'Nexus handler did not enter its operation.',
                ),
            ],
        );

        $this->worker($core, $service)->run();

        self::assertSame($expectedReason, $service->cancellationReason);
        self::assertSame(0, $core->shutdownCount);
        $completion = self::onlyCompletion($core);
        self::assertSame($token, $completion->getTaskToken());
        self::assertSame('ack_cancel', $completion->getStatus());
        self::assertTrue($completion->hasAckCancel());
        self::assertTrue($completion->getAckCancel());
    }

    // Failure serialization probes a deprecated generated oneof accessor under
    // @-suppression; PHPUnit's error handler is not coroutine-local.
    #[WithoutErrorHandler]
    public function testHandlerFailureDoesNotStopSubsequentTask(): void
    {
        $service = new NativeNexusWorkerTestServiceImpl();
        $failureToken = "\x00failure-token\xff";
        $successToken = "\x00success-token\x80";
        $core = new FakeNexusCoreWorker([
            $this->startTask($failureToken, 'process', 'fail'),
            $this->startTask($successToken, 'process', 'still-running'),
            null,
        ]);

        $this->worker($core, $service)->run();

        self::assertSame(0, $core->shutdownCount);
        $completions = self::completionsByToken($core);
        self::assertCount(2, $completions);
        self::assertArrayHasKey($failureToken, $completions);
        self::assertArrayHasKey($successToken, $completions);

        $failure = $completions[$failureToken];
        self::assertSame('failure', $failure->getStatus());
        self::assertTrue($failure->hasFailure());
        self::assertTrue($failure->getFailure()?->hasNexusHandlerFailureInfo());
        self::assertSame('Internal Nexus handler error', $failure->getFailure()?->getMessage());

        $success = $completions[$successToken];
        self::assertSame('completed', $success->getStatus());
        $payload = $success->getCompleted()?->getStartOperation()?->getSyncSuccess()?->getPayload();
        self::assertNotNull($payload);
        self::assertSame(
            'STILL-RUNNING',
            $this->dataConverter->fromPayload($payload, 'string'),
        );
    }

    public function testFatalPollFailureCancelsAndAcknowledgesOutstandingTask(): void
    {
        $service = new NativeNexusWorkerTestServiceImpl();
        $token = "\x00\xff\x80fatal-poll-token";
        $pollFailure = new \RuntimeException('Core Nexus poll failed');
        $core = new FakeNexusCoreWorker(
            [
                $this->startTask($token, 'wait', 'parked'),
                $pollFailure,
                null,
            ],
            [
                1 => static fn() => self::waitUntil(
                    static fn(): bool => $service->waiting,
                    'Nexus handler did not enter its operation.',
                ),
            ],
        );

        try {
            $this->worker($core, $service)->run();
            self::fail('Expected the original Core poll failure.');
        } catch (\RuntimeException $error) {
            self::assertSame($pollFailure, $error);
        }

        self::assertSame(1, $core->shutdownCount);
        self::assertSame(3, $core->pollCount);
        self::assertSame('Nexus worker is shutting down.', $service->cancellationReason);
        $completion = self::onlyCompletion($core);
        self::assertSame($token, $completion->getTaskToken());
        self::assertSame('ack_cancel', $completion->getStatus());
        self::assertTrue($completion->getAckCancel());
    }

    #[WithoutErrorHandler]
    public function testFatalPollFailureDrainsAlreadyIssuedRequestsBeforeReturning(): void
    {
        $service = new NativeNexusWorkerTestServiceImpl();
        $waitingToken = "\x00waiting-token\xff";
        $issuedToken = "\x00issued-during-shutdown\x80";
        $pollFailure = new \RuntimeException('Core Nexus poll failed');
        $core = new FakeNexusCoreWorker(
            [
                $this->startTask($waitingToken, 'wait', 'parked'),
                $pollFailure,
                $this->startTask($issuedToken, 'process', 'must-not-run'),
                null,
            ],
            [
                1 => static fn() => self::waitUntil(
                    static fn(): bool => $service->waiting,
                    'Nexus handler did not enter its operation.',
                ),
            ],
        );

        try {
            $this->worker($core, $service)->run();
            self::fail('Expected the original Core poll failure.');
        } catch (\RuntimeException $error) {
            self::assertSame($pollFailure, $error);
        }

        self::assertSame(1, $core->shutdownCount);
        self::assertSame(4, $core->pollCount);
        self::assertSame([], $service->processedInputs);
        self::assertSame('Nexus worker is shutting down.', $service->cancellationReason);

        $completions = self::completionsByToken($core);
        self::assertCount(2, $completions);
        self::assertSame('ack_cancel', $completions[$waitingToken]->getStatus());
        self::assertSame('failure', $completions[$issuedToken]->getStatus());
        self::assertSame(
            'UNAVAILABLE',
            $completions[$issuedToken]
                ->getFailure()
                ?->getNexusHandlerFailureInfo()
                ?->getType(),
        );
        self::assertSame(
            'Internal Nexus handler error',
            $completions[$issuedToken]->getFailure()?->getMessage(),
        );
    }

    public function testCancellationDuringFatalPollBackoffDoesNotSkipCoreShutdownSentinel(): void
    {
        $service = new NativeNexusWorkerTestServiceImpl();
        $pollFailure = new \RuntimeException('Core Nexus poll failed');
        $core = new FakeNexusCoreWorker([$pollFailure, null]);
        $workerCoroutine = null;
        $core->onShutdown = static function () use (&$workerCoroutine): void {
            \Async\spawn(static function () use (&$workerCoroutine): void {
                // Yield until spawn() has returned the worker coroutine, then
                // cancel while its fatal-poll backoff is suspended.
                \Async\delay(0);
                \assert($workerCoroutine instanceof \Async\Coroutine);
                $workerCoroutine->cancel();
            });
        };

        $workerCoroutine = \Async\spawn(fn() => $this->worker($core, $service)->run());

        try {
            \Async\await($workerCoroutine);
            self::fail('Expected the original Core poll failure.');
        } catch (\RuntimeException $error) {
            self::assertSame($pollFailure, $error);
        }

        self::assertSame(1, $core->shutdownCount);
        self::assertSame(2, $core->pollCount);
    }

    #[WithoutErrorHandler]
    public function testCancellationBeforeHandlerStartsDoesNotDropNextCoreTask(): void
    {
        $service = new NativeNexusWorkerTestServiceImpl();
        $cancelledToken = "\x00cancel-before-start\xff";
        $nextToken = "\x00next-issued-task\x80";
        $core = new FakeNexusCoreWorker(
            [
                $this->startTask($cancelledToken, 'wait', 'never-starts'),
                self::cancelTask($cancelledToken, NexusTaskCancelReason::WORKER_SHUTDOWN),
                $this->startTask($nextToken, 'process', 'still-runs'),
                null,
            ],
            [
                // Let the first spawned coroutine take its first scheduler
                // turn only after Core has already cancelled its token.
                2 => static fn() => \Async\delay(1),
            ],
        );

        $this->worker($core, $service)->run();

        self::assertSame(0, $core->shutdownCount);
        self::assertSame(4, $core->pollCount);
        self::assertFalse($service->waiting);
        self::assertSame(['still-runs'], $service->processedInputs);

        $completions = self::completionsByToken($core);
        self::assertCount(2, $completions);
        self::assertSame('ack_cancel', $completions[$cancelledToken]->getStatus());
        self::assertSame('completed', $completions[$nextToken]->getStatus());

        $payload = $completions[$nextToken]
            ->getCompleted()
            ?->getStartOperation()
            ?->getSyncSuccess()
            ?->getPayload();
        self::assertNotNull($payload);
        self::assertSame(
            'STILL-RUNS',
            $this->dataConverter->fromPayload($payload, 'string'),
        );
    }

    protected function setUp(): void
    {
        $this->dataConverter = DataConverter::createDefault();
    }

    private static function cancelTask(string $token, int $reason): string
    {
        return (new NexusTask())
            ->setCancelTask(
                (new CancelNexusTask())
                    ->setTaskToken($token)
                    ->setReason($reason),
            )
            ->serializeToString();
    }

    private static function waitUntil(\Closure $condition, string $failure): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if ($condition()) {
                return;
            }

            \Async\delay(1);
        }

        throw new \RuntimeException($failure);
    }

    private static function onlyCompletion(FakeNexusCoreWorker $core): NexusTaskCompletion
    {
        self::assertCount(1, $core->completions);

        return self::parseCompletion($core->completions[0]);
    }

    /**
     * @return array<string, NexusTaskCompletion>
     */
    private static function completionsByToken(FakeNexusCoreWorker $core): array
    {
        $result = [];
        foreach ($core->completions as $bytes) {
            $completion = self::parseCompletion($bytes);
            $result[$completion->getTaskToken()] = $completion;
        }

        return $result;
    }

    private static function parseCompletion(string $bytes): NexusTaskCompletion
    {
        $completion = new NexusTaskCompletion();
        $completion->mergeFromString($bytes);

        return $completion;
    }

    private function worker(
        NexusCoreWorkerInterface $core,
        NativeNexusWorkerTestServiceImpl $service,
    ): NexusWorker {
        $reader = new NexusServiceReader(new AttributeReader());
        $prototype = $reader->fromClass($service::class)->withInstance($service);
        $repository = new NexusServiceCollection();
        $repository->add($prototype, false);

        return new NexusWorker(
            $core,
            new NexusTaskHandler(
                $repository,
                $this->dataConverter,
                new Environment(),
            ),
            $this->dataConverter,
            'test-namespace',
            'test-task-queue',
        );
    }

    private function startTask(
        string $token,
        string $operation,
        string $input,
    ): string {
        $request = (new Request())->setStartOperation(
            (new StartOperationRequest())
                ->setService('NativeNexusWorkerTestService')
                ->setOperation($operation)
                ->setRequestId('request-' . \bin2hex($token))
                ->setPayload($this->dataConverter->toPayload($input)),
        );
        $pollResponse = (new PollNexusTaskQueueResponse())
            ->setTaskToken($token)
            ->setRequest($request);

        return (new NexusTask())
            ->setTask($pollResponse)
            ->setEndpoint('test-endpoint')
            ->serializeToString();
    }
}
