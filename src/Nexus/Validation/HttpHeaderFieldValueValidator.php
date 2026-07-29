<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Nexus\Validation;

use Temporal\Nexus\Exception\InvalidArgumentException;

/**
 * @internal Validates a non-empty HTTP field value by bytes.
 */
final class HttpHeaderFieldValueValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * Valid bytes are HTAB, SP, VCHAR (0x21-0x7E), and obs-text
     * (0x80-0xFF). Other controls and DEL are rejected.
     *
     * @throws InvalidArgumentException
     *
     * @psalm-mutation-free
     */
    public static function assertNonEmpty(string $value, string $label): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$label} must not be empty");
        }

        if (\preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value, $matches, \PREG_OFFSET_CAPTURE) === 1) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be a valid HTTP field value; got %d bytes, first invalid byte at offset %d',
                $label,
                \strlen($value),
                $matches[0][1],
            ));
        }
    }
}
