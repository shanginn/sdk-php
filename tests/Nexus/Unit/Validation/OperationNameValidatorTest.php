<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Nexus\Unit\Validation;

use Temporal\Nexus\Exception\InvalidArgumentException;
use Temporal\Nexus\Validation\OperationNameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationNameValidator::class)]
final class OperationNameValidatorTest extends TestCase
{
    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation Name must not be empty');
        OperationNameValidator::assert('');
    }

    public function testAcceptsArbitraryNonEmptyNexusName(): void
    {
        OperationNameValidator::assert("charge / card\n💳");

        $this->addToAssertionCount(1);
    }

    public function testProtocolValidatorAllowsTemporalReservedPrefix(): void
    {
        OperationNameValidator::assert('__temporal_internal');

        $this->addToAssertionCount(1);
    }
}
