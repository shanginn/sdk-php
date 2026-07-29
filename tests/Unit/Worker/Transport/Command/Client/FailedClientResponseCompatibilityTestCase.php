<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\Transport\Command\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Worker\Transport\Command\Client\FailedClientResponse;

#[CoversClass(FailedClientResponse::class)]
final class FailedClientResponseCompatibilityTestCase extends TestCase
{
    public function testFailureConstructorArgumentRemainsOptionalAndNullable(): void
    {
        $parameter = (new \ReflectionMethod(FailedClientResponse::class, '__construct'))
            ->getParameters()[1];

        self::assertTrue($parameter->isOptional());
        self::assertTrue($parameter->allowsNull());
        self::assertNull($parameter->getDefaultValue());
        self::assertInstanceOf(FailedClientResponse::class, new FailedClientResponse('legacy-id'));
    }

    public function testExplicitFailureStillRoundTrips(): void
    {
        $failure = new \RuntimeException('failed');
        $response = new FailedClientResponse(42, $failure);

        self::assertSame(42, $response->getID());
        self::assertSame($failure, $response->getFailure());
    }
}
