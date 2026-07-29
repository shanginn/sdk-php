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
 * Temporal-specific restrictions layered on top of the Nexus RPC name rules.
 *
 * @internal
 */
final class TemporalNameValidator
{
    public const RESERVED_PREFIX = '__temporal_';

    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * @throws InvalidArgumentException
     *
     * @psalm-mutation-free
     */
    public static function assertNotReserved(string $name, string $label): void
    {
        if (\str_starts_with($name, self::RESERVED_PREFIX)) {
            throw new InvalidArgumentException(\sprintf(
                '%s must not start with the reserved prefix `%s`.',
                $label,
                self::RESERVED_PREFIX,
            ));
        }
    }
}
