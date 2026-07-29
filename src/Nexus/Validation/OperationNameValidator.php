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
 * Nexus operation names are arbitrary non-empty strings. Transports are
 * responsible for encoding them when they are used in a URL.
 */
final class OperationNameValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * @throws InvalidArgumentException
     */
    public static function assert(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Operation Name must not be empty');
        }
    }
}
