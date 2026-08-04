<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Internal\Workflow;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\Type;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteNexusOperationInput;
use Temporal\Interceptor\NexusWorkflowOutboundCallsInterceptor;
use Temporal\Internal\Declaration\Prototype\NexusOperationPrototype;
use Temporal\Internal\Declaration\Prototype\NexusServicePrototype;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Workflow\NexusServiceProxy;
use Temporal\Internal\Workflow\Process\DeferredFiber;
use Temporal\Internal\Workflow\Process\FiberSuspension;
use Temporal\Workflow\NexusOperationHandle;
use Temporal\Workflow\NexusOperationOptions;
use Temporal\Workflow\NexusOperationStubInterface;
use Temporal\Workflow\NexusWorkflowContextInterface;

use function React\Promise\resolve;

/**
 * @group unit
 * @group nexus
 */
final class NexusServiceProxyTestCase extends TestCase
{
    public function testInterceptorEndpointRewriteChangesOutgoingOptions(): void
    {
        $captured = null;
        $context = $this->makeContext($captured);
        $proxy = $this->makeProxy(
            $context,
            new class implements NexusWorkflowOutboundCallsInterceptor {
                use WorkflowOutboundCallsInterceptorTrait;

                public function executeNexusOperation(
                    ExecuteNexusOperationInput $input,
                    callable $next,
                ): PromiseInterface {
                    return $next($input->with(endpoint: 'rewritten-ep'));
                }
            },
        );

        $this->execute($proxy, $context);

        self::assertInstanceOf(NexusOperationOptions::class, $captured);
        self::assertSame('rewritten-ep', $captured->endpoint);
        self::assertSame('OrderService', $captured->service);
    }

    public function testInterceptorServiceRewriteChangesOutgoingOptions(): void
    {
        $captured = null;
        $context = $this->makeContext($captured);
        $proxy = $this->makeProxy(
            $context,
            new class implements NexusWorkflowOutboundCallsInterceptor {
                use WorkflowOutboundCallsInterceptorTrait;

                public function executeNexusOperation(
                    ExecuteNexusOperationInput $input,
                    callable $next,
                ): PromiseInterface {
                    return $next($input->with(service: 'RewrittenService'));
                }
            },
        );

        $this->execute($proxy, $context);

        self::assertInstanceOf(NexusOperationOptions::class, $captured);
        self::assertSame('orig-ep', $captured->endpoint);
        self::assertSame('RewrittenService', $captured->service);
    }

    public function testWithoutInterceptorsOptionsPassThroughUnchanged(): void
    {
        $captured = null;
        $context = $this->makeContext($captured);
        $proxy = $this->makeProxy($context);

        $this->execute($proxy, $context);

        self::assertInstanceOf(NexusOperationOptions::class, $captured);
        self::assertSame('orig-ep', $captured->endpoint);
        self::assertSame('OrderService', $captured->service);
    }

    public function testUnknownMethodThrowsBadMethodCall(): void
    {
        $captured = null;
        $proxy = $this->makeProxy($this->makeContext($captured));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('has no operation method "unknownMethod"');

        $proxy->unknownMethod();
    }

    private function makeProxy(
        NexusWorkflowContextInterface $ctx,
        NexusWorkflowOutboundCallsInterceptor ...$interceptors,
    ): NexusServiceProxy {
        $reflection = new \ReflectionClass(NexusProxyTestService::class);
        $operation = new NexusOperationPrototype(
            name: 'place-order',
            methodName: 'placeOrder',
            inputType: Type::create(Type::TYPE_STRING),
            outputType: Type::create(Type::TYPE_STRING),
            async: false,
            handler: $reflection->getMethod('placeOrder'),
        );

        return new NexusServiceProxy(
            NexusProxyTestService::class,
            new NexusServicePrototype('OrderService', ['place-order' => $operation], $reflection),
            NexusOperationOptions::new()->withEndpoint('orig-ep')->withService('OrderService'),
            $ctx,
            Pipeline::prepare($interceptors),
        );
    }

    private function makeContext(?NexusOperationOptions &$captured): NexusWorkflowContextInterface
    {
        $ctx = $this->createMock(NexusWorkflowContextInterface::class);
        $ctx->method('newUntypedNexusOperationStub')
            ->willReturnCallback(static function (NexusOperationOptions $options) use (&$captured) {
                $captured = $options;
                return new class($options) implements NexusOperationStubInterface {
                    public function __construct(
                        private readonly NexusOperationOptions $options,
                    ) {}

                    public function getOptions(): NexusOperationOptions
                    {
                        return $this->options;
                    }

                    public function execute(
                        string $operation,
                        array $args = [],
                        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
                        array $nexusHeaders = [],
                    ): mixed {
                        return null;
                    }

                    public function executeAsync(
                        string $operation,
                        array $args = [],
                        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
                        array $nexusHeaders = [],
                    ): PromiseInterface {
                        return resolve(null);
                    }

                    public function start(
                        string $operation,
                        array $args = [],
                        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
                        array $nexusHeaders = [],
                    ): NexusOperationHandle {
                        return new NexusOperationHandle(null, resolve(EncodedValues::empty()), $returnType);
                    }

                    public function startAsync(
                        string $operation,
                        array $args = [],
                        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
                        array $nexusHeaders = [],
                    ): PromiseInterface {
                        return resolve(
                            new NexusOperationHandle(null, resolve(EncodedValues::empty()), $returnType),
                        );
                    }
                };
            });

        return $ctx;
    }

    private function execute(NexusServiceProxy $proxy, NexusWorkflowContextInterface $context): void
    {
        $fiber = DeferredFiber::fromHandler(
            static fn(): mixed => $proxy->placeOrder('order-1'),
            EncodedValues::empty(),
            $context,
        );

        $suspension = $fiber->start();
        self::assertInstanceOf(FiberSuspension::class, $suspension);
        self::assertTrue($suspension->preserveCancellationFailure);
        self::assertNull($fiber->resume(null));
        self::assertNull($fiber->getReturn());
    }
}

interface NexusProxyTestService
{
    public function placeOrder(string $order): string;
}
