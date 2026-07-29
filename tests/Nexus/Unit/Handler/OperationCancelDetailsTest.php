<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Nexus\Unit\Handler;

use Temporal\Nexus\Exception\InvalidArgumentException;
use Temporal\Nexus\Handler\OperationCancelDetails;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationCancelDetails::class)]
final class OperationCancelDetailsTest extends TestCase
{
    public function testHoldsOperationToken(): void
    {
        $d = new OperationCancelDetails('tok-1');
        self::assertSame('tok-1', $d->operationToken);
    }

    public function testRejectsEmptyToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationCancelDetails('');
    }

    public function testAcceptsTokenWithHttpFieldWhitespaceAndObsText(): void
    {
        $details = new OperationCancelDetails("opaque token\t\xFF");

        self::assertSame("opaque token\t\xFF", $details->operationToken);
    }

    public function testRejectsTokenWithInvalidHttpFieldByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationCancelDetails("bad\ntoken");
    }
}
