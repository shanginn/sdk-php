<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Nexus;

use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\NexusServiceReader;
use Temporal\Nexus\Handler\Internal\MethodOperationHandler;
use Temporal\Nexus\Handler\OperationCancelDetails;
use Temporal\Nexus\Handler\OperationStartDetails;
use Temporal\Nexus\OperationInfo;
use Temporal\Nexus\OperationState;
use PHPUnit\Framework\MockObject\MockObject;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Common\WorkflowIdConflictPolicy;
use Temporal\Internal\Nexus\NexusContext;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Internal\WorkflowRunOperationToken;
use Temporal\Nexus\Internal\WorkflowClientNamespace;
use Temporal\Nexus\Handler\OperationContext;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\NexusOperationContext;
use Temporal\Nexus\WorkflowHandle;
use PHPUnit\Framework\Attributes\CoversClass;
use Temporal\Nexus\Handler\Internal\WorkflowRunStarter;
use Temporal\Nexus\WorkflowRunOperation;
use Temporal\Tests\Nexus\Fixtures\Service\GreetingService;
use Temporal\Tests\Unit\AbstractUnit;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\Environment\EnvironmentInterface;

/**
 * Mocks the WorkflowClient and exercises the {@see WorkflowRunOperation}
 * helper end-to-end (sans the actual Temporal server).
 *
 * @group unit
 * @group nexus
 */
#[CoversClass(WorkflowRunOperation::class)]
#[CoversClass(WorkflowRunStarter::class)]
#[CoversClass(WorkflowClientNamespace::class)]
#[CoversClass(MethodOperationHandler::class)]
final class WorkflowRunOperationTestCase extends AbstractUnit
{
    private const NS = 'sample-ns';
    private const WID = 'sample-wid';

    /** @var WorkflowClientInterface&MockObject */
    private WorkflowClientInterface $client;

    private EnvironmentInterface $env;

    /** @var array<array-key, mixed> */
    private array $clientMetadata;

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidClientNamespaceMetadataProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty value' => [['Temporal-Namespace' => ['']]];
        yield 'multiple values' => [['Temporal-Namespace' => [self::NS, self::NS]]];
        yield 'ambiguous casing' => [[
            'Temporal-Namespace' => [self::NS],
            'temporal-namespace' => [self::NS],
        ]];
        yield 'non-string value' => [['Temporal-Namespace' => [123]]];
    }

    public function testStartReturnsAsyncTokenAndStartsWorkflow(): void
    {
        $stub = $this->createMock(WorkflowStubInterface::class);

        $capturedOptions = null;
        $this->client->expects(self::once())
            ->method('newWorkflowStub')
            ->willReturnCallback(static function (string $class, ?WorkflowOptions $options = null) use ($stub, &$capturedOptions) {
                $capturedOptions = $options;
                return $stub;
            });

        $this->client->expects(self::once())
            ->method('start')
            ->with($stub, 42)
            ->willReturn($this->createMock(\Temporal\Workflow\WorkflowRunInterface::class));

        $handle = WorkflowHandle::fromWorkflowMethod(
            FakeWorkflow::class,
            WorkflowOptions::new()->withWorkflowId(self::WID),
            42,
        );

        $info = WorkflowRunStarter::start(
            $handle,
            new OperationStartDetails(
                requestId: 'req-1',
                callbackUrl: 'https://callback.example/done',
                callbackHeaders: ['X-Caller' => 'demo'],
                links: [],
            ),
        );

        self::assertInstanceOf(OperationInfo::class, $info);
        self::assertSame(OperationState::Running, $info->state);

        $expectedToken = WorkflowRunOperationToken::generate(self::NS, self::WID);
        self::assertSame($expectedToken, $info->token);

        self::assertNotNull($capturedOptions);
        self::assertSame('req-1', $capturedOptions->requestId);
        self::assertCount(1, $capturedOptions->completionCallbacks);

        $callback = $capturedOptions->completionCallbacks[0];
        self::assertSame('https://callback.example/done', $callback->url);
        self::assertSame('demo', $callback->headers['X-Caller']);
        self::assertSame($expectedToken, $callback->headers['Nexus-Operation-Token']);
        self::assertSame($expectedToken, $callback->headers['Nexus-Operation-Id']);
    }

    public function testStartOverwritesCallerProvidedTokenHeaders(): void
    {
        $stub = $this->createMock(WorkflowStubInterface::class);

        $captured = null;
        $this->client->method('newWorkflowStub')->willReturnCallback(
            static function (string $class, ?WorkflowOptions $options = null) use ($stub, &$captured) {
                $captured = $options;
                return $stub;
            },
        );
        $this->client->method('start')->willReturn($this->createMock(\Temporal\Workflow\WorkflowRunInterface::class));

        WorkflowRunStarter::start(
            WorkflowHandle::fromWorkflowMethod(
                FakeWorkflow::class,
                WorkflowOptions::new()->withWorkflowId(self::WID),
            ),
            new OperationStartDetails(
                requestId: 'req-1',
                callbackUrl: 'https://callback.example/done',
                callbackHeaders: [
                    'nexus-operation-token' => 'caller-token',
                    'Nexus-Operation-Id' => 'caller-id',
                ],
                links: [],
            ),
        );

        $callback = $captured->completionCallbacks[0];
        $expectedToken = WorkflowRunOperationToken::generate(self::NS, self::WID);
        self::assertArrayNotHasKey('nexus-operation-token', $callback->headers);
        self::assertSame($expectedToken, $callback->headers['Nexus-Operation-Token']);
        self::assertSame($expectedToken, $callback->headers['Nexus-Operation-Id']);
        self::assertCount(2, $callback->headers);
    }

    public function testStartWithoutCallbackOmitsCompletionCallback(): void
    {
        $stub = $this->createMock(WorkflowStubInterface::class);

        $captured = null;
        $this->client->method('newWorkflowStub')->willReturnCallback(
            static function (string $class, ?WorkflowOptions $options = null) use ($stub, &$captured) {
                $captured = $options;
                return $stub;
            },
        );
        $this->client->method('start')->willReturn($this->createMock(\Temporal\Workflow\WorkflowRunInterface::class));

        WorkflowRunStarter::start(
            WorkflowHandle::fromWorkflowMethod(
                FakeWorkflow::class,
                WorkflowOptions::new()->withWorkflowId(self::WID),
            ),
            new OperationStartDetails(requestId: 'req-no-cb', callbackUrl: null, callbackHeaders: [], links: []),
        );

        self::assertSame([], $captured->completionCallbacks);
        self::assertSame('req-no-cb', $captured->requestId);
    }

    public function testStartUsesNexusContextTaskQueueByDefault(): void
    {
        $stub = $this->createMock(WorkflowStubInterface::class);

        $captured = null;
        $this->client->method('newWorkflowStub')->willReturnCallback(
            static function (string $class, ?WorkflowOptions $options = null) use ($stub, &$captured) {
                $captured = $options;
                return $stub;
            },
        );
        $this->client->method('start')->willReturn($this->createMock(\Temporal\Workflow\WorkflowRunInterface::class));

        WorkflowRunStarter::start(
            WorkflowHandle::fromWorkflowMethod(
                FakeWorkflow::class,
                WorkflowOptions::new()->withWorkflowId(self::WID),
            ),
            new OperationStartDetails(requestId: 'req-test', callbackUrl: null, callbackHeaders: [], links: []),
        );

        self::assertSame('tq', $captured->taskQueue, 'task queue should fall back to Nexus context value');
    }

    public function testStartPreservesUserProvidedTaskQueue(): void
    {
        $stub = $this->createMock(WorkflowStubInterface::class);

        $captured = null;
        $this->client->method('newWorkflowStub')->willReturnCallback(
            static function (string $class, ?WorkflowOptions $options = null) use ($stub, &$captured) {
                $captured = $options;
                return $stub;
            },
        );
        $this->client->method('start')->willReturn($this->createMock(\Temporal\Workflow\WorkflowRunInterface::class));

        WorkflowRunStarter::start(
            WorkflowHandle::fromWorkflowMethod(
                FakeWorkflow::class,
                WorkflowOptions::new()
                    ->withWorkflowId(self::WID)
                    ->withTaskQueue('explicit-queue'),
            ),
            new OperationStartDetails(requestId: 'req-test', callbackUrl: null, callbackHeaders: [], links: []),
        );

        self::assertSame('explicit-queue', $captured->taskQueue);
    }

    public function testStartAddsSelfLinkToOperationContext(): void
    {
        $stub = $this->createMock(WorkflowStubInterface::class);
        $this->client->method('newWorkflowStub')->willReturn($stub);

        $run = $this->createMock(\Temporal\Workflow\WorkflowRunInterface::class);
        $run->method('getExecution')->willReturn(
            new \Temporal\Workflow\WorkflowExecution(self::WID, 'run-xyz'),
        );
        $this->client->method('start')->willReturn($run);

        WorkflowRunStarter::start(
            WorkflowHandle::fromWorkflowMethod(
                FakeWorkflow::class,
                WorkflowOptions::new()->withWorkflowId(self::WID),
            ),
            new OperationStartDetails(requestId: 'req-1', callbackUrl: null, callbackHeaders: [], links: []),
        );

        $links = Nexus::getCurrentOperationContext()->links->all();
        self::assertCount(1, $links);
        self::assertSame(\Temporal\Internal\Nexus\NexusLinkConverter::TYPE_WORKFLOW_EVENT, $links[0]->type);
        self::assertStringContainsString('/namespaces/' . self::NS . '/', $links[0]->uri);
        self::assertStringContainsString('/workflows/' . self::WID . '/run-xyz/history', $links[0]->uri);
        self::assertStringContainsString('eventType=WorkflowExecutionStarted', $links[0]->uri);
    }

    public function testStartDoesNotForceConflictPolicyAndAlwaysAttachesOnConflictOptions(): void
    {
        $captured = $this->captureStartOptions(
            WorkflowOptions::new()->withWorkflowId(self::WID),
        );

        self::assertSame(WorkflowIdConflictPolicy::Unspecified, $captured->workflowIdConflictPolicy);
        self::assertNotNull($captured->onConflictOptions);
    }

    public function testStartPreservesExplicitFailConflictPolicyAndStillAttachesOnConflictOptions(): void
    {
        $captured = $this->captureStartOptions(
            WorkflowOptions::new()
                ->withWorkflowId(self::WID)
                ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::Fail),
        );

        self::assertSame(WorkflowIdConflictPolicy::Fail, $captured->workflowIdConflictPolicy);
        self::assertNotNull($captured->onConflictOptions);
    }

    public function testStartPreservesExplicitUseExistingConflictPolicy(): void
    {
        $captured = $this->captureStartOptions(
            WorkflowOptions::new()
                ->withWorkflowId(self::WID)
                ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::UseExisting),
        );

        self::assertSame(WorkflowIdConflictPolicy::UseExisting, $captured->workflowIdConflictPolicy);
        self::assertNotNull($captured->onConflictOptions);
    }

    public function testStartAcceptsExplicitWorkflowId(): void
    {
        $captured = $this->captureStartOptions(
            WorkflowOptions::new()->withWorkflowId(self::WID),
        );

        self::assertSame(self::WID, $captured->workflowId);
    }

    public function testStartResolvesClientNamespaceMetadataCaseInsensitively(): void
    {
        $this->clientMetadata = ['tEmPoRaL-NaMeSpAcE' => [self::NS]];

        $captured = $this->captureStartOptions(
            WorkflowOptions::new()->withWorkflowId(self::WID),
        );

        self::assertSame(self::WID, $captured->workflowId);
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidClientNamespaceMetadataProvider')]
    public function testStartFailsSafelyForInvalidClientNamespaceMetadata(array $metadata): void
    {
        $this->clientMetadata = $metadata;
        $this->client->expects(self::never())->method('newWorkflowStub');
        $this->client->expects(self::never())->method('start');

        try {
            WorkflowRunStarter::start(
                WorkflowHandle::fromWorkflowMethod(
                    FakeWorkflow::class,
                    WorkflowOptions::new()->withWorkflowId(self::WID),
                ),
                new OperationStartDetails(requestId: 'req-invalid-ns', callbackUrl: null),
            );
            self::fail('Expected invalid WorkflowClient namespace metadata to be rejected.');
        } catch (HandlerException $e) {
            self::assertSame(ErrorType::Internal, $e->errorType);
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString(
                'WorkflowClient metadata must contain exactly one non-empty Temporal-Namespace value',
                $e->getMessage(),
            );
            self::assertStringNotContainsString(self::NS, $e->getMessage());
        }
    }

    public function testStartRejectsWorkflowClientNamespaceMismatchBeforeStarting(): void
    {
        $this->clientMetadata = ['Temporal-Namespace' => ['other-namespace']];
        $this->client->expects(self::never())->method('newWorkflowStub');
        $this->client->expects(self::never())->method('start');

        try {
            WorkflowRunStarter::start(
                WorkflowHandle::fromWorkflowMethod(
                    FakeWorkflow::class,
                    WorkflowOptions::new()->withWorkflowId(self::WID),
                ),
                new OperationStartDetails(requestId: 'req-mismatched-ns', callbackUrl: null),
            );
            self::fail('Expected WorkflowClient namespace mismatch to be rejected.');
        } catch (HandlerException $e) {
            self::assertSame(ErrorType::Internal, $e->errorType);
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString(
                'WorkflowClient namespace must match the Nexus operation and operation-token namespaces',
                $e->getMessage(),
            );
            self::assertStringNotContainsString('other-namespace', $e->getMessage());
            self::assertStringNotContainsString(self::NS, $e->getMessage());
        }
    }

    public function testCancelDecodesTokenAndCancelsWorkflow(): void
    {
        $token = WorkflowRunOperationToken::generate(self::NS, self::WID);
        $stub = $this->createMock(WorkflowStubInterface::class);
        $stub->expects(self::once())->method('cancel');

        $this->client->expects(self::once())
            ->method('newUntypedRunningWorkflowStub')
            ->with(self::WID)
            ->willReturn($stub);

        WorkflowRunOperation::cancel($token);
    }

    public function testCancelRejectsBadTokenAsBadRequest(): void
    {
        try {
            WorkflowRunOperation::cancel('not-a-real-token');
            self::fail('Expected HandlerException for malformed token');
        } catch (HandlerException $e) {
            self::assertSame(ErrorType::BadRequest, $e->errorType);
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('failed to parse operation token', $e->getMessage());
        }
    }

    public function testCancelRejectsTokenNamespaceMismatchBeforeCancellingWorkflow(): void
    {
        $token = WorkflowRunOperationToken::generate('other-ns', self::WID);

        $this->client->expects(self::never())->method('newUntypedRunningWorkflowStub');

        try {
            WorkflowRunOperation::cancel($token);
            self::fail('Expected token namespace mismatch to be rejected.');
        } catch (HandlerException $e) {
            self::assertSame(ErrorType::Internal, $e->errorType);
            self::assertFalse($e->isRetryable());
            self::assertStringNotContainsString('other-ns', $e->getMessage());
            self::assertStringNotContainsString(self::NS, $e->getMessage());
        }
    }

    public function testCancelRejectsActiveOperationNamespaceMismatchBeforeCancellingWorkflow(): void
    {
        Nexus::setCurrentContext(new NexusContext(
            operation: new NexusOperationContext('other-active-ns', 'tq'),
            workflowClient: $this->client,
            current: new OperationContext(service: 'svc', operation: 'op', env: $this->env),
        ));
        $token = WorkflowRunOperationToken::generate(self::NS, self::WID);
        $this->client->expects(self::never())->method('newUntypedRunningWorkflowStub');

        try {
            WorkflowRunOperation::cancel($token);
            self::fail('Expected active operation namespace mismatch to be rejected.');
        } catch (HandlerException $e) {
            self::assertSame(ErrorType::Internal, $e->errorType);
            self::assertFalse($e->isRetryable());
            self::assertStringNotContainsString('other-active-ns', $e->getMessage());
            self::assertStringNotContainsString(self::NS, $e->getMessage());
        }
    }

    public function testHandlerWithoutCancelRoutineAutoCancelsWorkflowRun(): void
    {
        $token = WorkflowRunOperationToken::generate(self::NS, self::WID);

        $stub = $this->createMock(WorkflowStubInterface::class);
        $stub->expects(self::once())->method('cancel');

        $this->client->expects(self::once())
            ->method('newUntypedRunningWorkflowStub')
            ->with(self::WID)
            ->willReturn($stub);

        $service = new GreetingService(static fn(string $n): string => $n);
        $this->handlerFor($service, 'sayHello2')->cancel(
            new OperationContext(service: 'svc', operation: 'sayHello2', env: $this->env),
            new OperationCancelDetails(operationToken: $token),
        );
    }

    protected function setUp(): void
    {
        $this->env = new Environment();
        $this->clientMetadata = ['Temporal-Namespace' => [self::NS]];
        $this->client = $this->createMock(WorkflowClientInterface::class);
        $serviceClient = $this->createMock(ServiceClientInterface::class);
        $serviceClient->method('getContext')->willReturnCallback(
            fn(): Context => Context::default()->withMetadata($this->clientMetadata),
        );
        $this->client->method('getServiceClient')->willReturn($serviceClient);
        Nexus::setCurrentContext(new NexusContext(
            operation: new NexusOperationContext(self::NS, 'tq'),
            workflowClient: $this->client,
            current: new OperationContext(service: 'svc', operation: 'op', env: $this->env),
        ));
    }

    protected function tearDown(): void
    {
        Nexus::setCurrentContext(null);
    }

    private function handlerFor(object $service, string $operation): MethodOperationHandler
    {
        $prototype = (new NexusServiceReader(new AttributeReader()))->fromClass(\get_class($service));
        $operationPrototype = $prototype->getOperations()[$operation];

        return new MethodOperationHandler(
            instance: $service,
            startMethod: new \ReflectionMethod($service, $operationPrototype->methodName),
            operation: $operationPrototype,
        );
    }

    private function captureStartOptions(WorkflowOptions $handleOptions): WorkflowOptions
    {
        $stub = $this->createMock(WorkflowStubInterface::class);

        $captured = null;
        $this->client->method('newWorkflowStub')->willReturnCallback(
            static function (string $class, ?WorkflowOptions $options = null) use ($stub, &$captured) {
                $captured = $options;
                return $stub;
            },
        );
        $this->client->method('start')->willReturn($this->createMock(\Temporal\Workflow\WorkflowRunInterface::class));

        WorkflowRunStarter::start(
            WorkflowHandle::fromWorkflowMethod(FakeWorkflow::class, $handleOptions),
            new OperationStartDetails(requestId: 'req-policy', callbackUrl: null, callbackHeaders: [], links: []),
        );

        self::assertNotNull($captured);
        return $captured;
    }
}

/**
 * @internal Local fixture — needed only as a class-string for WorkflowHandle.
 */
final class FakeWorkflow {}
