<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Nexus\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Nexus\Exception\InvalidArgumentException;
use Temporal\Nexus\Validation\TemporalNameValidator;

#[CoversClass(TemporalNameValidator::class)]
final class TemporalNameValidatorTest extends TestCase
{
    public function testRejectsReservedPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved prefix `__temporal_`');

        TemporalNameValidator::assertNotReserved('__temporal_internal', 'Service Name');
    }

    public function testPrefixIsCaseSensitive(): void
    {
        TemporalNameValidator::assertNotReserved('__Temporal_internal', 'Service Name');

        $this->addToAssertionCount(1);
    }
}
