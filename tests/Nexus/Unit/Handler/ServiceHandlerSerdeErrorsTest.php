<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Nexus\Unit\Handler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\OperationException;
use Temporal\Nexus\Exception\RetryBehavior;
use Temporal\Nexus\Handler\OperationContext;
use Temporal\Nexus\Handler\OperationStartDetails;
use Temporal\Nexus\Handler\Internal\ServiceHandler;
use Temporal\Nexus\NexusOperationContext;
use Temporal\Tests\Nexus\Fixtures\Service\ThrowingGreetingService;
use Temporal\Tests\Nexus\Support\BindNexusService;
use Temporal\Tests\Nexus\Support\ExceptionAssertions;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\Environment\EnvironmentInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceHandler::class)]
final class ServiceHandlerSerdeErrorsTest extends TestCase
{
    use BindNexusService;
    use ExceptionAssertions;

    private static EnvironmentInterface $env;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$env = new Environment();
    }

    public function testDeserializeFailureWrapsAsBadRequestHandlerException(): void
    {
        $converter = self::failingDeserializeConverter();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Failed deserializing Nexus operation input.',
                self::callback(static fn(array $context): bool =>
                    ($context['service'] ?? null) === 'GreetingServiceInterface'
                    && ($context['operation'] ?? null) === 'sayHello1'
                    && ($context['type'] ?? null) === 'string'
                    && ($context['exception'] ?? null) instanceof \JsonException),
            );
        $handler = self::newHandler($converter, logger: $logger);

        $e = self::assertThrown(HandlerException::class, static fn() => $handler->startOperation(
            self::newContext('sayHello1'),
            new OperationStartDetails(requestId: 'r1'),
            self::input($converter),
            null,
            new NexusOperationContext(),
        ));

        self::assertStringContainsString(
            'Failed deserializing input for GreetingServiceInterface/sayHello1 as string.',
            $e->getMessage(),
        );
        self::assertStringNotContainsString('Bad JSON', $e->getMessage());
        self::assertSame(ErrorType::BadRequest, $e->errorType);
        self::assertNull($e->getPrevious());
    }

    public function testSerializeFailureWrapsAsInternalHandlerException(): void
    {
        $converter = self::failingSerializeConverter();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Failed serializing Nexus operation result.',
                self::callback(static fn(array $context): bool =>
                    ($context['service'] ?? null) === 'GreetingServiceInterface'
                    && ($context['operation'] ?? null) === 'sayHello1'
                    && ($context['type'] ?? null) === 'string'
                    && ($context['exception'] ?? null) instanceof \RuntimeException),
            );
        $handler = self::newHandler($converter, logger: $logger);

        $e = self::assertThrown(HandlerException::class, static fn() => $handler->startOperation(
            self::newContext('sayHello1'),
            new OperationStartDetails(requestId: 'r1'),
            self::input($converter),
            null,
            new NexusOperationContext(),
        ));

        self::assertStringContainsString(
            'Failed serializing result for GreetingServiceInterface/sayHello1 as string.',
            $e->getMessage(),
        );
        self::assertStringNotContainsString('cannot serialize', $e->getMessage());
        self::assertSame(ErrorType::Internal, $e->errorType);
        self::assertNull($e->getPrevious());
    }

    public function testOperationExceptionFromHandlerIsNotWrapped(): void
    {
        $converter = DataConverter::createDefault();
        $handler = self::newHandler(
            $converter,
            new ThrowingGreetingService(hello1Throw: OperationException::failed('intentional business failure')),
        );

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('intentional business failure');
        $handler->startOperation(
            self::newContext('sayHello1'),
            new OperationStartDetails(requestId: 'r1'),
            EncodedValues::fromValues(['anything'], $converter),
            null,
            new NexusOperationContext(),
        );
    }

    public function testHandlerExceptionFromHandlerIsNotDoubleWrapped(): void
    {
        $converter = DataConverter::createDefault();
        $handler = self::newHandler(
            $converter,
            new ThrowingGreetingService(hello1Throw: HandlerException::create(
                ErrorType::Unauthorized,
                'no auth',
                retryBehavior: RetryBehavior::NonRetryable,
            )),
        );

        $e = self::assertThrown(HandlerException::class, static fn() => $handler->startOperation(
            self::newContext('sayHello1'),
            new OperationStartDetails(requestId: 'r1'),
            EncodedValues::fromValues(['anything'], $converter),
            null,
            new NexusOperationContext(),
        ));

        self::assertSame(ErrorType::Unauthorized, $e->errorType);
        self::assertSame('no auth', $e->getMessage());
        self::assertSame(RetryBehavior::NonRetryable, $e->retryBehavior);
    }

    public function testDeserializeErrorMessageContainsServiceOperationAndType(): void
    {
        $converter = self::failingDeserializeConverter();
        $handler = self::newHandler($converter);

        $e = self::assertThrown(HandlerException::class, static fn() => $handler->startOperation(
            self::newContext('sayHello2'),
            new OperationStartDetails(requestId: 'r1'),
            self::input($converter),
            null,
            new NexusOperationContext(),
        ));

        self::assertStringContainsString('GreetingServiceInterface', $e->getMessage());
        self::assertStringContainsString('sayHello2', $e->getMessage());
        self::assertStringContainsString('string', $e->getMessage());
    }

    public function testSerializeErrorMessageContainsOutputType(): void
    {
        $converter = self::failingSerializeConverter();
        $handler = self::newHandler($converter);

        $e = self::assertThrown(HandlerException::class, static fn() => $handler->startOperation(
            self::newContext('sayHello1'),
            new OperationStartDetails(requestId: 'r1'),
            self::input($converter),
            null,
            new NexusOperationContext(),
        ));

        self::assertStringContainsString('as string', $e->getMessage());
    }

    private static function newHandler(
        DataConverterInterface $dataConverter,
        ?ThrowingGreetingService $instance = null,
        LoggerInterface $logger = new NullLogger(),
    ): ServiceHandler {
        return ServiceHandler::create(
            dataConverter: $dataConverter,
            instances: [self::bindNexusService($instance ?? new ThrowingGreetingService())],
            logger: $logger,
        );
    }

    private static function newContext(string $operation): OperationContext
    {
        return new OperationContext(
            service: 'GreetingServiceInterface',
            operation: $operation,
            env: self::$env,
        );
    }

    /**
     * Wrap a single dummy proto Payload so ServiceHandler routes through
     * `DataConverterInterface::fromPayload()` (the path that may raise).
     */
    private static function input(DataConverterInterface $dataConverter): EncodedValues
    {
        $payload = new Payload();
        $payload->setData('garbage');
        $payloads = new \Temporal\Api\Common\V1\Payloads(['payloads' => [$payload]]);
        return EncodedValues::fromPayloads($payloads, $dataConverter);
    }

    private static function failingDeserializeConverter(): DataConverterInterface
    {
        return new class implements DataConverterInterface {
            public function fromPayload(Payload $payload, mixed $type): mixed
            {
                throw new \JsonException('Bad JSON');
            }

            public function toPayload(mixed $value): Payload
            {
                $p = new Payload();
                $p->setData((string) $value);
                return $p;
            }
        };
    }

    private static function failingSerializeConverter(): DataConverterInterface
    {
        return new class implements DataConverterInterface {
            public function fromPayload(Payload $payload, mixed $type): mixed
            {
                return $payload->getData();
            }

            public function toPayload(mixed $value): Payload
            {
                throw new \RuntimeException('cannot serialize');
            }
        };
    }
}
