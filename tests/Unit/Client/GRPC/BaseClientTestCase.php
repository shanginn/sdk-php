<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Client\GRPC;

use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\GetSystemInfoRequest;
use Temporal\Api\Workflowservice\V1\GetSystemInfoResponse;
use Temporal\Api\Workflowservice\V1\GetSystemInfoResponse\Capabilities;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\BaseClient;
use Temporal\Client\GRPC\Connection\Connection;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\TimeoutException;
use Temporal\Internal\Interceptor\Pipeline;

final class BaseClientTestCase extends TestCase
{
    public function testGetCapabilitiesUsesCache(): void
    {
        $calls = 0;
        $client = $this->createClientMock(static function (string $method) use (&$calls): object {
            self::assertSame('GetSystemInfo', $method);
            ++$calls;
            return self::systemInfo();
        });

        $capabilities0 = $client->getServerCapabilities();
        $capabilities1 = $client->getServerCapabilities();

        self::assertTrue($capabilities0?->supportsSchedules);
        self::assertSame($capabilities0, $capabilities1);
        self::assertSame(1, $calls);
    }

    public function testGetCapabilitiesClearsCacheOnDisconnect(): void
    {
        $calls = 0;
        $client = $this->createClientMock(static function () use (&$calls): object {
            ++$calls;
            return self::systemInfo();
        });

        $capabilities0 = $client->getServerCapabilities();
        $client->getConnection()->disconnect();
        $capabilities1 = $client->getServerCapabilities();

        self::assertNotSame($capabilities0, $capabilities1);
        self::assertSame(2, $calls);
    }

    public function testCloseMarksCompatibilityConnectionClosed(): void
    {
        $client = $this->createClientMock();
        $client->close();

        self::assertFalse($client->getConnection()->isConnected());
    }

    public function testContextIsImmutable(): void
    {
        $client = $this->createClientMock();
        $context = $client->getContext();
        $dynamic = $context->withTimeout(1.234);
        $client2 = $client->withContext($dynamic);

        self::assertSame($context, $client->getContext());
        self::assertSame($dynamic, $client2->getContext());
        self::assertNotSame($client, $client2);
        self::assertNotSame($dynamic->getDeadline(), $dynamic->getDeadline());
        self::assertNull($context->getDeadline());
    }

    public function testStaticDeadlineIsStable(): void
    {
        $deadline = new \DateTimeImmutable('+1 second');
        $context = $this->createClientMock()->getContext()->withDeadline($deadline);

        self::assertSame($deadline, $context->getDeadline());
        self::assertSame($context->getDeadline(), $context->getDeadline());
    }

    public function testWithAuthKeyAddsBearerMetadataAtInvocationTime(): void
    {
        $client = $this->createClientMock();
        $context = $client->getContext();
        $authenticated = $client->withAuthKey('test-key');

        self::assertNotSame($client, $authenticated);
        self::assertSame($context, $client->getContext());
        self::assertSame($context, $authenticated->getContext());

        $ctx1 = $client->testCall()->ctx;
        self::assertInstanceOf(ContextInterface::class, $ctx1);
        self::assertArrayNotHasKey('Authorization', $ctx1->getMetadata());

        $ctx2 = $authenticated->testCall()->ctx;
        self::assertInstanceOf(ContextInterface::class, $ctx2);
        self::assertSame(['Bearer test-key'], $ctx2->getMetadata()['Authorization']);
    }

    public function testWithDynamicAuthKeyReadsStringableForEveryInvocation(): void
    {
        $client = $this->createClientMock()->withAuthKey(new class implements \Stringable {
            public function __toString(): string
            {
                static $counter = 0;
                return 'test-key-' . ++$counter;
            }
        });

        self::assertSame(['Bearer test-key-1'], $client->testCall()->ctx->getMetadata()['Authorization']);
        self::assertSame(['Bearer test-key-2'], $client->testCall()->ctx->getMetadata()['Authorization']);
    }

    public function testDeadlineReachedMapsRetryableFailureToTimeout(): void
    {
        $client = $this->createClientMock(static function (): never {
            throw self::serviceError(StatusCode::UNKNOWN);
        })->withInterceptorPipeline(null);

        $client = $client->withContext(
            $client->getContext()
                ->withDeadline(new \DateTimeImmutable('-1 second'))
                ->withRetryOptions(RpcRetryOptions::new()->withMaximumAttempts(2)),
        );

        self::expectException(TimeoutException::class);
        $client->testCall();
    }

    public function testCustomTransportExceptionIsNotRetriedOrWrapped(): void
    {
        $client = $this->createClientMock(static function (): never {
            throw new \RuntimeException('foo');
        })->withInterceptorPipeline(null);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('foo');
        $client->testCall();
    }

    public function testMaximumAttemptsRethrowsLastServiceError(): void
    {
        $attempt = 0;
        $client = $this->createClientMock(static function () use (&$attempt): never {
            throw self::serviceError(StatusCode::UNKNOWN, ++$attempt);
        })->withInterceptorPipeline(null);

        $client = $client->withContext(
            $client->getContext()
                ->withDeadline(new \DateTimeImmutable('+2 seconds'))
                ->withRetryOptions(RpcRetryOptions::new()->withMaximumAttempts(3)->withBackoffCoefficient(1)),
        );

        try {
            $client->testCall();
            self::fail('Expected exception');
        } catch (ServiceClientException $error) {
            self::assertSame(3, $error->attempt);
            self::assertSame(3, $attempt);
        }
    }

    private function createClientMock(?callable $handler = null): BaseClient
    {
        $handler ??= static fn(string $method, object $arg, ContextInterface $ctx): object => (object) [
            'method' => $method,
            'arg' => $arg,
            'ctx' => $ctx,
        ];

        $client = new class(new Connection()) extends ServiceClient {
            private \Closure $handler;

            public function setHandler(callable $handler): void
            {
                $this->handler = $handler(...);
            }

            public function testCall(): object
            {
                return $this->invoke('testCall', (object) []);
            }

            public function getSystemInfo(
                GetSystemInfoRequest $arg,
                ?ContextInterface $ctx = null,
            ): GetSystemInfoResponse {
                $response = ($this->handler)(
                    'GetSystemInfo',
                    $arg,
                    $ctx ?? $this->getContext(),
                    [],
                );

                if (!$response instanceof GetSystemInfoResponse) {
                    throw new \UnexpectedValueException('Expected a GetSystemInfoResponse test double.');
                }

                return $response;
            }

            protected function performCall(
                string $method,
                object $arg,
                ContextInterface $ctx,
                array $options,
            ): object {
                return ($this->handler)($method, $arg, $ctx, $options);
            }
        };
        $client->setHandler($handler);

        return $client->withInterceptorPipeline(Pipeline::prepare([
            new class implements \Temporal\Interceptor\GrpcClientInterceptor {
                public function interceptCall(
                    string $method,
                    object $arg,
                    ContextInterface $ctx,
                    callable $next,
                ): object {
                    return (object) [
                        'method' => $method,
                        'arg' => $arg,
                        'ctx' => $ctx,
                        'next' => $next,
                    ];
                }
            },
        ]));
    }

    private static function systemInfo(): GetSystemInfoResponse
    {
        return (new GetSystemInfoResponse())
            ->setCapabilities((new Capabilities())->setSupportsSchedules(true))
            ->setServerVersion('1.2.3');
    }

    private static function serviceError(int $code, int $attempt = 0): ServiceClientException
    {
        return new class((object) ['code' => $code, 'metadata' => []], $attempt) extends ServiceClientException {
            public function __construct(\stdClass $status, public readonly int $attempt)
            {
                parent::__construct($status);
            }
        };
    }
}
