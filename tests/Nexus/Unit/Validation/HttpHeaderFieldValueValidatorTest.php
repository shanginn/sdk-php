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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpHeaderFieldValueValidator::class)]
final class HttpHeaderFieldValueValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function badCharProvider(): iterable
    {
        yield 'newline'         => ["a\nb", 1];
        yield 'carriage return' => ["a\rb", 1];
        yield 'null byte'       => ["a\0b", 1];
        yield 'control 0x01'    => ["a\x01b", 1];
        yield 'backspace'       => ["a\x08b", 1];
        yield 'vertical tab'    => ["a\x0Bb", 1];
        yield 'del'             => ["a\x7Fb", 1];
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Thing must not be empty');
        HttpHeaderFieldValueValidator::assertNonEmpty('', 'Thing');
    }

    public function testAcceptsHttpFieldValueBytes(): void
    {
        $value = "\t ";
        for ($c = 0x21; $c <= 0xFF; $c++) {
            if ($c === 0x7F) {
                continue;
            }
            $value .= \chr($c);
        }

        HttpHeaderFieldValueValidator::assertNonEmpty($value, 'Thing');

        self::assertTrue(true);
    }

    public function testErrorMessageIncludesByteLengthAndOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('got 6 bytes, first invalid byte at offset 2');
        HttpHeaderFieldValueValidator::assertNonEmpty("ok\nbad", 'Thing');
    }

    #[DataProvider('badCharProvider')]
    public function testRejectsNonPrintable(string $value, int $offset): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("first invalid byte at offset {$offset}");
        HttpHeaderFieldValueValidator::assertNonEmpty($value, 'Thing');
    }
}
