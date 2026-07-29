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
use Temporal\Nexus\Validation\ServiceNameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceNameValidator::class)]
final class ServiceNameValidatorTest extends TestCase
{
    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Name must not be empty');
        ServiceNameValidator::assert('');
    }

    public function testAcceptsArbitraryNonEmptyNexusName(): void
    {
        ServiceNameValidator::assert("payments / v1\n💳");

        $this->addToAssertionCount(1);
    }

    public function testProtocolValidatorAllowsTemporalReservedPrefix(): void
    {
        ServiceNameValidator::assert('__temporal_internal');

        $this->addToAssertionCount(1);
    }
}
