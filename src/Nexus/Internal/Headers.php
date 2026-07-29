<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Nexus\Internal;

use Temporal\Nexus\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Headers
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * Lowercase keys; last value wins on collision.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException(\sprintf(
                    'Nexus header names must be strings, got %s.',
                    \get_debug_type($key),
                ));
            }
            if (!\is_string($value)) {
                throw new InvalidArgumentException(\sprintf(
                    'Nexus header values must be strings, got %s.',
                    \get_debug_type($value),
                ));
            }

            $normalized[\strtolower($key)] = $value;
        }
        return $normalized;
    }
}
