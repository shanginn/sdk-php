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
 * Operation token — a non-empty valid HTTP field value.
 */
final class OperationTokenValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * @throws InvalidArgumentException
     */
    public static function assert(string $token): void
    {
        HttpHeaderFieldValueValidator::assertNonEmpty($token, 'Operation Token');
    }
}
