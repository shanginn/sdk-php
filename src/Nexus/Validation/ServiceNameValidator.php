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
 * Nexus service names are arbitrary non-empty strings. Transports are
 * responsible for encoding them when they are used in a URL.
 */
final class ServiceNameValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * @throws InvalidArgumentException
     *
     * @psalm-mutation-free
     */
    public static function assert(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Service Name must not be empty');
        }
    }
}
