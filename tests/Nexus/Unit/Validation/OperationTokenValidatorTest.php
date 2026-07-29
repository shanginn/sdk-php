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
use Temporal\Nexus\Validation\HttpHeaderFieldValueValidator;
use Temporal\Nexus\Validation\OperationTokenValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationTokenValidator::class)]
#[UsesClass(HttpHeaderFieldValueValidator::class)]
final class OperationTokenValidatorTest extends TestCase
{
    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation Token must not be empty');
        OperationTokenValidator::assert('');
    }

    public function testAcceptsValidHttpFieldValueIncludingWhitespaceAndObsText(): void
    {
        OperationTokenValidator::assert("token with space\tand-obs-text-\xFF");
        self::assertTrue(true);
    }

    public function testRejectsInvalidHttpFieldValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Operation Token.+valid HTTP field value/');
        OperationTokenValidator::assert("bad\ntoken");
    }
}
