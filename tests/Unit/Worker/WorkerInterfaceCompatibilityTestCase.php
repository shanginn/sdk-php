<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Testing\WorkerMock;
use Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface;
use Temporal\Worker\DispatcherInterface;
use Temporal\Worker\NexusWorkerInterface;
use Temporal\Worker\Transport\Command\ServerRequestInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

#[CoversClass(WorkerFactory::class)]
final class WorkerInterfaceCompatibilityTestCase extends TestCase
{
    public function testLegacyWorkerImplementationStillLoads(): void
    {
        $worker = new LegacyWorkerImplementation();

        self::assertInstanceOf(WorkerInterface::class, $worker);
        self::assertNotInstanceOf(NexusWorkerInterface::class, $worker);
        self::assertSame('legacy-worker', $worker->getID());
    }

    public function testConcreteFactoryCreatesNexusWorker(): void
    {
        $factory = new WorkerFactory(
            DataConverter::createDefault(),
            $this->createMock(RPCConnectionInterface::class),
        );

        self::assertInstanceOf(NexusWorkerInterface::class, $factory->newWorker('nexus-worker'));
    }

    public function testTestingWorkerStillWrapsLegacyWorker(): void
    {
        $worker = new WorkerMock(
            new LegacyWorkerImplementation(),
            $this->createMock(ActivityInvocationCacheInterface::class),
        );

        self::assertSame('legacy-worker', $worker->getID());
    }

    public function testLegacyWorkerFactoryOverrideRemainsCompatible(): void
    {
        $factory = new LegacyWorkerFactoryExtension();

        self::assertInstanceOf(LegacyWorkerImplementation::class, $factory->newWorker());

        $returnType = (new \ReflectionMethod(WorkerFactory::class, 'newWorker'))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(WorkerInterface::class, $returnType->getName());
    }

    public function testSubclassMayKeepLegacyWorkflowClientProperty(): void
    {
        $factory = new LegacyWorkerFactoryWithWorkflowClientProperty();

        self::assertSame('legacy-value', $factory->getLegacyWorkflowClientValue());
    }
}

final class LegacyWorkerImplementation implements WorkerInterface, DispatcherInterface
{
    public function getID(): string
    {
        return 'legacy-worker';
    }

    public function getOptions(): WorkerOptions
    {
        return WorkerOptions::new();
    }

    public function registerWorkflowTypes(string ...$class): WorkerInterface
    {
        return $this;
    }

    public function registerActivityFinalizer(\Closure $finalizer): WorkerInterface
    {
        return $this;
    }

    public function getWorkflows(): iterable
    {
        return [];
    }

    public function registerActivityImplementations(object ...$activity): WorkerInterface
    {
        return $this;
    }

    public function registerActivity(string $type, ?callable $factory = null): WorkerInterface
    {
        return $this;
    }

    public function getActivities(): iterable
    {
        return [];
    }

    public function dispatch(ServerRequestInterface $request, array $headers): PromiseInterface
    {
        throw new \LogicException('Not used by this compatibility fixture.');
    }
}

final class LegacyWorkerFactoryExtension extends WorkerFactory
{
    public function __construct() {}

    public function newWorker(
        string $taskQueue = WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
        ?WorkerOptions $options = null,
        ?ExceptionInterceptorInterface $exceptionInterceptor = null,
        ?PipelineProvider $interceptorProvider = null,
        ?LoggerInterface $logger = null,
    ): WorkerInterface {
        return new LegacyWorkerImplementation();
    }
}

final class LegacyWorkerFactoryWithWorkflowClientProperty extends WorkerFactory
{
    protected string $workflowClient = 'legacy-value';

    public function __construct() {}

    public function getLegacyWorkflowClientValue(): string
    {
        return $this->workflowClient;
    }
}
