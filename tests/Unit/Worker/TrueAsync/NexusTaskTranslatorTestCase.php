<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\TrueAsync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Nexus\V1\Request;
use Temporal\Api\Nexus\V1\Response;
use Temporal\DataConverter\DataConverter;
use Temporal\Internal\Declaration\Prototype\NexusServiceCollection;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\OperationException;
use Temporal\Nexus\Exception\RetryBehavior;
use Temporal\Nexus\Handler\MethodCanceller;
use Temporal\Nexus\NexusOperationContext;
use Temporal\Worker\Environment\Environment;
use Temporal\Worker\TrueAsync\NexusTaskTranslator;

#[CoversClass(NexusTaskTranslator::class)]
final class NexusTaskTranslatorTestCase extends TestCase
{
    private NexusTaskTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new NexusTaskTranslator(DataConverter::createDefault());
    }

    public function testCompletedPreservesTokenAndResponse(): void
    {
        $response = new Response();

        $completion = $this->translator->completed("\x00task-token", $response);

        self::assertSame("\x00task-token", $completion->getTaskToken());
        self::assertSame('completed', $completion->getStatus());
        self::assertSame($response, $completion->getCompleted());
    }

    public function testHandlerFailureUsesNexusHandlerFailureInfo(): void
    {
        $completion = $this->translator->handlerFailure(
            'handler-token',
            HandlerException::create(
                ErrorType::BadRequest,
                'invalid request',
                retryBehavior: RetryBehavior::Retryable,
            ),
        );

        self::assertSame('handler-token', $completion->getTaskToken());
        self::assertSame('failure', $completion->getStatus());
        self::assertTrue($completion->hasFailure());

        $failure = $completion->getFailure();
        self::assertNotNull($failure);
        self::assertTrue($failure->hasNexusHandlerFailureInfo());
        self::assertFalse($failure->hasApplicationFailureInfo());
        self::assertSame('invalid request', $failure->getMessage());
        self::assertSame('BAD_REQUEST', $failure->getNexusHandlerFailureInfo()?->getType());
        self::assertSame(1, $failure->getNexusHandlerFailureInfo()?->getRetryBehavior());
    }

    public function testInfrastructureHandlerFailureIsRedacted(): void
    {
        $completion = $this->translator->handlerFailure(
            'handler-token',
            HandlerException::create(
                ErrorType::Internal,
                'database password leaked',
                new \RuntimeException('private host name'),
                RetryBehavior::NonRetryable,
            ),
        );

        $failure = $completion->getFailure();
        self::assertNotNull($failure);
        self::assertSame('Internal Nexus handler error', $failure->getMessage());
        self::assertFalse($failure->hasCause());
        self::assertSame('INTERNAL', $failure->getNexusHandlerFailureInfo()?->getType());
        self::assertSame(2, $failure->getNexusHandlerFailureInfo()?->getRetryBehavior());
    }

    public function testFailedOperationIsCompletedWithApplicationFailure(): void
    {
        $completion = $this->translator->operationFailure(
            'operation-token',
            OperationException::failed('card declined'),
        );

        self::assertSame('completed', $completion->getStatus());
        self::assertFalse($completion->hasFailure());
        $failure = $completion->getCompleted()?->getStartOperation()?->getFailure();
        self::assertNotNull($failure);
        self::assertTrue($failure->hasApplicationFailureInfo());
        self::assertFalse($failure->hasCanceledFailureInfo());
        self::assertSame('card declined', $failure->getMessage());
        self::assertSame(
            'nexus.OperationError.failed',
            $failure->getApplicationFailureInfo()?->getType(),
        );
        self::assertTrue($failure->getApplicationFailureInfo()?->getNonRetryable());
    }

    public function testCanceledOperationIsCompletedWithCanceledFailure(): void
    {
        $completion = $this->translator->operationFailure(
            'operation-token',
            OperationException::canceled('customer canceled'),
        );

        self::assertSame('completed', $completion->getStatus());
        self::assertFalse($completion->hasFailure());
        $failure = $completion->getCompleted()?->getStartOperation()?->getFailure();
        self::assertNotNull($failure);
        self::assertTrue($failure->hasCanceledFailureInfo());
        self::assertFalse($failure->hasApplicationFailureInfo());
        self::assertSame('customer canceled', $failure->getMessage());
    }

    public function testCancellationAcknowledgementUsesAckCancelVariant(): void
    {
        $completion = $this->translator->acknowledgeCancellation("\xffcancel-token");

        self::assertSame("\xffcancel-token", $completion->getTaskToken());
        self::assertSame('ack_cancel', $completion->getStatus());
        self::assertTrue($completion->hasAckCancel());
        self::assertTrue($completion->getAckCancel());
    }

    public function testUnsupportedRequestVariantIsBadRequestHandlerFailure(): void
    {
        $handler = new NexusTaskHandler(
            new NexusServiceCollection(),
            DataConverter::createDefault(),
            new Environment(),
        );
        $environment = new Environment();

        try {
            $this->translator->invoke(
                $handler,
                new Request(),
                new NexusOperationContext(),
                new MethodCanceller($environment),
            );
            self::fail('Expected a bad-request HandlerException.');
        } catch (HandlerException $error) {
            self::assertSame(ErrorType::BadRequest, $error->errorType);
            self::assertSame(
                'Nexus request does not contain a supported operation variant.',
                $error->getMessage(),
            );
        }
    }
}
