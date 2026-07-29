<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Internal\Transport\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\Header;
use Temporal\Internal\Transport\Request\ExecuteNexusOperation;

#[CoversClass(ExecuteNexusOperation::class)]
final class ExecuteNexusOperationTestCase extends TestCase
{
    public function testCarriesSummaryInNestedWireOptions(): void
    {
        $request = new ExecuteNexusOperation(
            endpoint: 'payments',
            service: 'PaymentsService',
            operation: 'capture',
            args: EncodedValues::empty(),
            options: ['summary' => 'Capture the payment'],
            header: Header::empty(),
        );

        $wire = $request->getOptions();
        self::assertSame(
            [
                'endpoint' => 'payments',
                'service' => 'PaymentsService',
                'operation' => 'capture',
                'options' => ['summary' => 'Capture the payment'],
            ],
            \array_diff_key($wire, ['nexusHeaders' => true]),
        );
        self::assertInstanceOf(\stdClass::class, $wire['nexusHeaders']);
    }
}
