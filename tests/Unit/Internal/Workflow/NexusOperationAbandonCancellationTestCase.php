<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Internal\Workflow;

use Internal\Destroy\Destroyable;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\NexusOperationFailure;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Internal\Declaration\Prototype\WorkflowPrototype;
use Temporal\Internal\Declaration\WorkflowInstance\QueryDispatcher;
use Temporal\Internal\Declaration\WorkflowInstance\SignalDispatcher;
use Temporal\Internal\Declaration\WorkflowInstance\UpdateDispatcher;
use Temporal\Internal\Declaration\WorkflowInstanceInterface;
use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Transport\Request\ExecuteNexusOperation;
use Temporal\Internal\Transport\Request\GetNexusOperationStarted;
use Temporal\Internal\Workflow\Input;
use Temporal\Internal\Workflow\NexusOperationStub;
use Temporal\Internal\Workflow\Process\Scope;
use Temporal\Internal\Workflow\ScopeContext;
use Temporal\Internal\Workflow\WorkflowContext;
use Temporal\Tests\Unit\Framework\WorkerFactoryMock;
use Temporal\Worker\Logger\StderrLogger;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\NexusOperationOptions;

/**
 * @group unit
 * @group nexus
 */
final class NexusOperationAbandonCancellationTestCase extends TestCase
{
    private WorkerFactoryMock $factory;
    private RecordingNexusWorkflowContext $parentContext;
    private NexusCancellationScope $scope;
    private NexusOperationStub $stub;

    public function testAlreadyCancelledScopeDoesNotScheduleAbandonedNexusOperation(): void
    {
        $this->scope->cancel();

        try {
            $this->stub->start('run');
            self::fail('The local Nexus start must fail immediately in an already-cancelled scope.');
        } catch (CanceledFailure) {
            // Expected: the ExecuteNexusOperation request never reaches the parent context.
        }

        self::assertSame([], $this->parentContext->requests);
        self::assertCount(0, $this->factory->getQueue());
    }

    public function testCancelAfterSendBeforeStartedAckRejectsBothLocalRequestsWithoutWireCancel(): void
    {
        $startPromise = $this->stub->start('run');

        $sent = \iterator_to_array($this->factory->getQueue(), false);
        self::assertCount(2, $sent);
        self::assertInstanceOf(ExecuteNexusOperation::class, $sent[0]);
        self::assertInstanceOf(GetNexusOperationStarted::class, $sent[1]);

        $requestErrors = [];
        foreach ($this->parentContext->promises as $name => $promise) {
            $promise->then(
                null,
                static function (\Throwable $error) use (&$requestErrors, $name): void {
                    $requestErrors[$name] = $error;
                },
            );
        }

        $startSettled = false;
        $startError = null;
        $startPromise->then(
            static function () use (&$startSettled): void {
                $startSettled = true;
            },
            static function (\Throwable $error) use (&$startSettled, &$startError): void {
                $startSettled = true;
                $startError = $error;
            },
        );

        // Both requests have left the local queue, but the start acknowledgement has not arrived.
        $this->scope->cancel();
        $this->factory->tick();

        self::assertSame(
            [ExecuteNexusOperation::NAME, GetNexusOperationStarted::NAME],
            \array_keys($requestErrors),
        );
        self::assertContainsOnlyInstancesOf(CanceledFailure::class, $requestErrors);

        self::assertTrue($startSettled, 'The public start promise must not remain pending after cancellation.');
        self::assertInstanceOf(NexusOperationFailure::class, $startError);
        self::assertInstanceOf(CanceledFailure::class, $startError->getPrevious());

        self::assertCount(
            0,
            $this->factory->getQueue(),
            'ABANDON must reject locally without emitting a Cancel/RequestCancelNexusOperation command.',
        );
    }

    protected function setUp(): void
    {
        $this->factory = new WorkerFactoryMock(DataConverter::createDefault());
        $services = ServiceContainer::fromWorkerFactory(
            $this->factory,
            ExceptionInterceptor::createDefault(),
            new SimplePipelineProvider(),
            new StderrLogger(),
        );

        $workflow = new \stdClass();
        $prototype = new WorkflowPrototype('nexus-cancellation-test', null, new \ReflectionClass($workflow));
        $instance = $this->createMockForIntersectionOfInterfaces([
            WorkflowInstanceInterface::class,
            Destroyable::class,
        ]);
        $instance->method('getQueryDispatcher')
            ->willReturn(new QueryDispatcher($prototype, $workflow));
        $instance->method('getSignalDispatcher')
            ->willReturn(new SignalDispatcher($prototype, $workflow));
        $instance->method('getUpdateDispatcher')
            ->willReturn(new UpdateDispatcher($prototype, $workflow));

        $this->parentContext = new RecordingNexusWorkflowContext(
            $services,
            $services->client,
            $instance,
            new Input(),
            EncodedValues::empty(),
        );
        $this->parentContext->enableRequests();

        $this->scope = new NexusCancellationScope($services);
        Workflow::setCurrentContext($this->scope->bind($this->parentContext));

        $this->stub = new NexusOperationStub(
            $services->marshaller,
            NexusOperationOptions::new()
                ->withEndpoint('test-endpoint')
                ->withService('TestService')
                ->withCancellationType(NexusOperationCancellationType::Abandon),
            Header::empty(),
        );
    }

    protected function tearDown(): void
    {
        Workflow::setCurrentContext(null);
    }
}

final class RecordingNexusWorkflowContext extends WorkflowContext
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var array<string, PromiseInterface> */
    public array $promises = [];

    public function enableRequests(): void
    {
        $this->readonly = false;
    }

    public function request(
        RequestInterface $request,
        bool $cancellable = true,
        bool $waitResponse = true,
    ): PromiseInterface {
        $promise = parent::request($request, $cancellable, $waitResponse);
        $this->requests[] = $request;
        $this->promises[$request->getName()] = $promise;

        return $promise;
    }
}

final class NexusCancellationScope extends Scope
{
    public function bind(WorkflowContext $context): ScopeContext
    {
        $this->setContext($context);

        return $this->scopeContext;
    }
}
