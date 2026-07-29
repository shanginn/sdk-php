<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Nexus;

use PHPUnit\Framework\Attributes\CoversClass;
use React\Promise\Deferred;
use Spiral\Attributes\AttributeReader;
use Temporal\DataConverter\DataConverter;
use Temporal\Internal\Declaration\Prototype\NexusServiceCollection;
use Temporal\Internal\Declaration\Reader\NexusServiceReader;
use Temporal\Internal\Marshaller\Mapper\AttributeMapperFactory;
use Temporal\Internal\Marshaller\Marshaller;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Internal\Transport\Router\CancelNexusOperation;
use Temporal\Nexus\Attribute\AsyncOperation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Handler\MethodCancellationListenerInterface;
use Temporal\Nexus\Handler\OperationCancelDetails;
use Temporal\Nexus\Handler\OperationContext;
use Temporal\Nexus\Handler\OperationHandlerInterface;
use Temporal\Nexus\Handler\OperationStartDetails;
use Temporal\Nexus\Handler\OperationStartResult;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\OperationInfo;
use Temporal\Nexus\OperationState;
use Temporal\Tests\Unit\AbstractUnit;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\Environment\EnvironmentInterface;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\TickInfo;
use Temporal\Worker\Transport\RPCConnectionInterface;

#[Service(name: 'RouteHeaderService')]
interface RouteHeaderService
{
    #[AsyncOperation(output: 'string', input: 'string')]
    public function op(): RouteHeaderOpHandler;
}

final class RouteHeaderOpHandler implements OperationHandlerInterface
{
    public function start(
        OperationContext $context,
        OperationStartDetails $details,
        mixed $param,
    ): OperationStartResult {
        return OperationStartResult::async(new OperationInfo('tok', OperationState::Running));
    }

    public function cancel(
        OperationContext $context,
        OperationCancelDetails $details,
    ): void {
        RouteHeaderServiceImpl::$capturedCancelHeaders = Nexus::getCurrentOperationContext()->headers->all();

        $listener = new class implements MethodCancellationListenerInterface {
            public bool $called = false;

            public function cancelled(): void
            {
                $this->called = true;
            }
        };
        $context->addMethodCancellationListener($listener);
        RouteHeaderServiceImpl::$capturedMethodCancelled = $context->isMethodCancelled();
        RouteHeaderServiceImpl::$capturedCancellationReason = $context->getMethodCancellationReason();
        RouteHeaderServiceImpl::$capturedListenerCalled = $listener->called;
    }
}

class RouteHeaderServiceImpl implements RouteHeaderService
{
    /** @var array<string, string> */
    public static array $capturedCancelHeaders = [];

    public static bool $capturedMethodCancelled = false;
    public static ?string $capturedCancellationReason = null;
    public static bool $capturedListenerCalled = false;

    public function op(): RouteHeaderOpHandler
    {
        return new RouteHeaderOpHandler();
    }
}

/**
 * Unit tests for the `CancelNexusOperation` router — the `options['headers']`
 * map (sent by RoadRunner on the cancel command) must surface on the handler's
 * OperationContext, symmetric with the start path.
 *
 * @group unit
 * @group nexus
 */
#[CoversClass(CancelNexusOperation::class)]
final class CancelNexusOperationRouteTestCase extends AbstractUnit
{
    use AwaitsNexusPromise;

    private EnvironmentInterface $env;

    public function testRouteName(): void
    {
        $route = new CancelNexusOperation($this->buildHandler(), $this->buildMarshaller());

        self::assertSame('CancelNexusOperation', $route->getName());
    }

    public function testForwardsOptionHeadersToHandlerContext(): void
    {
        $route = new CancelNexusOperation($this->buildHandler(), $this->buildMarshaller());
        $request = $this->makeRequest([
            'service' => 'RouteHeaderService',
            'operation' => 'op',
            'operationToken' => 'tok',
            'headers' => [
                'X-Nexus-Trace-Id' => 'trace-1',
                'Authorization' => 'Bearer xyz',
            ],
        ]);

        $deferred = new Deferred();
        $route->handle($request, [], $deferred);

        $this->assertResolved($deferred);
        // OperationContext lowercases header keys on construction.
        self::assertSame('trace-1', RouteHeaderServiceImpl::$capturedCancelHeaders['x-nexus-trace-id'] ?? null);
        self::assertSame('Bearer xyz', RouteHeaderServiceImpl::$capturedCancelHeaders['authorization'] ?? null);
    }

    public function testMissingHeadersResolvesWithEmptyContextHeaders(): void
    {
        $route = new CancelNexusOperation($this->buildHandler(), $this->buildMarshaller());
        $request = $this->makeRequest([
            'service' => 'RouteHeaderService',
            'operation' => 'op',
            'operationToken' => 'tok',
        ]);

        $deferred = new Deferred();
        $route->handle($request, [], $deferred);

        $this->assertResolved($deferred);
        self::assertSame([], RouteHeaderServiceImpl::$capturedCancelHeaders);
    }

    public function testInvocationIDAttachesRemoteMethodCancellerToCancelContext(): void
    {
        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc
            ->expects(self::once())
            ->method('call')
            ->with('temporal.GetNexusMethodCancellation', ['invocationId' => 42])
            ->willReturn([
                'cancelled' => true,
                'reason' => 'context deadline exceeded',
            ]);

        $route = new CancelNexusOperation(
            $this->buildHandler(),
            $this->buildMarshaller(),
            $this->env,
            $rpc,
        );
        $request = $this->makeRequest([
            'service' => 'RouteHeaderService',
            'operation' => 'op',
            'operationToken' => 'tok',
            'invocationId' => 42,
            // Keep the local deadline in the future so this test proves the
            // RoadRunner RPC poll is the source of cancellation.
            'headers' => ['Request-Timeout' => '3600s'],
        ]);

        $deferred = new Deferred();
        $route->handle($request, [], $deferred);

        $this->assertResolved($deferred);
        self::assertTrue(RouteHeaderServiceImpl::$capturedMethodCancelled);
        self::assertTrue(RouteHeaderServiceImpl::$capturedListenerCalled);
        self::assertSame(
            'context deadline exceeded',
            RouteHeaderServiceImpl::$capturedCancellationReason,
        );
    }

    public function testMalformedRequestTimeoutRejectsAsBadRequest(): void
    {
        $route = new CancelNexusOperation($this->buildHandler(), $this->buildMarshaller());
        $request = $this->makeRequest([
            'service' => 'RouteHeaderService',
            'operation' => 'op',
            'operationToken' => 'tok',
            'headers' => ['Request-Timeout' => ' 5s'],
        ]);

        $deferred = new Deferred();
        $route->handle($request, [], $deferred);

        $error = null;
        $deferred->promise()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(\Temporal\Nexus\Exception\HandlerException::class, $error);
        self::assertSame(\Temporal\Nexus\Exception\ErrorType::BadRequest, $error->errorType);
        self::assertFalse($error->isRetryable());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new Environment();
        RouteHeaderServiceImpl::$capturedCancelHeaders = [];
        RouteHeaderServiceImpl::$capturedMethodCancelled = false;
        RouteHeaderServiceImpl::$capturedCancellationReason = null;
        RouteHeaderServiceImpl::$capturedListenerCalled = false;
    }

    private function buildHandler(): NexusTaskHandler
    {
        $reader = new NexusServiceReader(new AttributeReader());
        $collection = new NexusServiceCollection();
        $prototype = $reader->fromClass(RouteHeaderServiceImpl::class)->withInstance(new RouteHeaderServiceImpl());
        $collection->add($prototype, false);

        return new NexusTaskHandler($collection, DataConverter::createDefault(), $this->env);
    }

    private function buildMarshaller(): Marshaller
    {
        return new Marshaller(new AttributeMapperFactory(new AttributeReader()));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function makeRequest(array $options): ServerRequest
    {
        return new ServerRequest(
            name: 'CancelNexusOperation',
            info: new TickInfo(new \DateTimeImmutable()),
            options: $options,
        );
    }
}
