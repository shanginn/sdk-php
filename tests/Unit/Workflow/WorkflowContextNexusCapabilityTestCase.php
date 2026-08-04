<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Workflow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Temporal\Activity\ActivityOptionsInterface;
use Temporal\Common\SearchAttributes\SearchAttributeUpdate;
use Temporal\DataConverter\Type;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Workflow;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow\ActivityStubInterface;
use Temporal\Workflow\ChildWorkflowOptions;
use Temporal\Workflow\ChildWorkflowStubInterface;
use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ExternalWorkflowStubInterface;
use Temporal\Workflow\Mutex;
use Temporal\Workflow\NexusOperationOptions;
use Temporal\Workflow\NexusWorkflowContextInterface;
use Temporal\Workflow\TimerOptions;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowInfo;

final class WorkflowContextNexusCapabilityTestCase extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function nexusFacadeProvider(): iterable
    {
        yield 'typed stub' => [
            static fn(): object => Workflow::newNexusServiceStub(
                \stdClass::class,
                NexusOperationOptions::new(),
            ),
        ];
        yield 'untyped stub' => [
            static fn(): object => Workflow::newUntypedNexusOperationStub(
                NexusOperationOptions::new(),
            ),
        ];
        yield 'direct execution' => [
            static fn(): mixed => Workflow::executeNexusOperation('operation'),
        ];
    }

    public function testBaseContextCanExistWithoutNexusCapability(): void
    {
        $context = new BaseWorkflowContext();

        self::assertInstanceOf(WorkflowContextInterface::class, $context);
        self::assertNotInstanceOf(NexusWorkflowContextInterface::class, $context);
        self::assertFalse(\method_exists($context, 'executeNexusOperation'));
    }

    #[DataProvider('nexusFacadeProvider')]
    public function testNexusFacadeFailsClearlyWithoutNexusCapability(\Closure $call): void
    {
        Workflow::setCurrentContext(new BaseWorkflowContext());

        try {
            $call();
            self::fail('Expected a clear Nexus capability error.');
        } catch (\LogicException $e) {
            self::assertSame(
                'The active Workflow context does not support Nexus operations.',
                $e->getMessage(),
            );
        } finally {
            Workflow::setCurrentContext(null);
        }
    }
}

/**
 * Minimal base workflow context without the optional Nexus capability.
 */
final class BaseWorkflowContext implements WorkflowContextInterface
{
    public function now(): \DateTimeInterface
    {
        throw new \LogicException('Not used.');
    }

    public function isReplaying(): bool
    {
        throw new \LogicException('Not used.');
    }

    public function getInfo(): WorkflowInfo
    {
        throw new \LogicException('Not used.');
    }

    public function getInput(): ValuesInterface
    {
        throw new \LogicException('Not used.');
    }

    public function getLastCompletionResult(mixed $type = null): mixed
    {
        throw new \LogicException('Not used.');
    }

    public function registerQuery(string $queryType, callable $handler, string $description): self
    {
        throw new \LogicException('Not used.');
    }

    public function registerSignal(string $queryType, callable $handler, string $description): self
    {
        throw new \LogicException('Not used.');
    }

    public function registerDynamicSignal(callable $handler): self
    {
        throw new \LogicException('Not used.');
    }

    public function registerDynamicQuery(callable $handler): self
    {
        throw new \LogicException('Not used.');
    }

    public function registerDynamicUpdate(callable $handler, ?callable $validator = null): self
    {
        throw new \LogicException('Not used.');
    }

    public function registerUpdate(
        string $name,
        callable $handler,
        ?callable $validator,
        string $description,
    ): static {
        throw new \LogicException('Not used.');
    }

    public function request(
        RequestInterface $request,
        bool $cancellable = true,
        bool $waitResponse = true,
    ): PromiseInterface {
        throw new \LogicException('Not used.');
    }

    public function getVersion(string $changeId, int $minSupported, int $maxSupported): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function sideEffect(callable $context): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function complete(?array $result = null, ?\Throwable $failure = null): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function panic(?\Throwable $failure = null): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function timer($interval, ?TimerOptions $options = null): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function continueAsNew(
        string $type,
        array $args = [],
        ?ContinueAsNewOptions $options = null,
    ): PromiseInterface {
        throw new \LogicException('Not used.');
    }

    public function newContinueAsNewStub(string $class, ?ContinueAsNewOptions $options = null): object
    {
        throw new \LogicException('Not used.');
    }

    public function executeChildWorkflow(
        string $type,
        array $args = [],
        ?ChildWorkflowOptions $options = null,
        $returnType = null,
    ): PromiseInterface {
        throw new \LogicException('Not used.');
    }

    public function newChildWorkflowStub(
        string $class,
        ?ChildWorkflowOptions $options = null,
    ): object {
        throw new \LogicException('Not used.');
    }

    public function newUntypedChildWorkflowStub(
        string $type,
        ?ChildWorkflowOptions $options = null,
    ): ChildWorkflowStubInterface {
        throw new \LogicException('Not used.');
    }

    public function newExternalWorkflowStub(string $class, WorkflowExecution $execution): object
    {
        throw new \LogicException('Not used.');
    }

    public function newUntypedExternalWorkflowStub(
        WorkflowExecution $execution,
    ): ExternalWorkflowStubInterface {
        throw new \LogicException('Not used.');
    }

    public function executeActivity(
        string $type,
        array $args = [],
        ?ActivityOptionsInterface $options = null,
        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
    ): PromiseInterface {
        throw new \LogicException('Not used.');
    }

    public function newActivityStub(
        string $class,
        ?ActivityOptionsInterface $options = null,
    ): object {
        throw new \LogicException('Not used.');
    }

    public function newUntypedActivityStub(
        ?ActivityOptionsInterface $options = null,
    ): ActivityStubInterface {
        throw new \LogicException('Not used.');
    }

    public function await(callable|Mutex|PromiseInterface ...$conditions): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function awaitWithTimeout(
        $interval,
        callable|Mutex|PromiseInterface ...$conditions,
    ): PromiseInterface {
        throw new \LogicException('Not used.');
    }

    public function getStackTrace(): string
    {
        throw new \LogicException('Not used.');
    }

    public function allHandlersFinished(): bool
    {
        throw new \LogicException('Not used.');
    }

    public function upsertMemo(array $values): void
    {
        throw new \LogicException('Not used.');
    }

    public function upsertSearchAttributes(array $searchAttributes): void
    {
        throw new \LogicException('Not used.');
    }

    public function upsertTypedSearchAttributes(SearchAttributeUpdate ...$updates): void
    {
        throw new \LogicException('Not used.');
    }

    public function uuid(): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function uuid4(): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function uuid7(?\DateTimeInterface $dateTime = null): PromiseInterface
    {
        throw new \LogicException('Not used.');
    }

    public function getLogger(): LoggerInterface
    {
        throw new \LogicException('Not used.');
    }

    public function getInstance(): object
    {
        throw new \LogicException('Not used.');
    }

    public function getCurrentDetails(): ?string
    {
        throw new \LogicException('Not used.');
    }

    public function setCurrentDetails(?string $details): void
    {
        throw new \LogicException('Not used.');
    }
}
