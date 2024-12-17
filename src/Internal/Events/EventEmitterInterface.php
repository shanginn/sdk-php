<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Events;

/**
 * @template-covariant T of string
 */
interface EventEmitterInterface
{
    /**
     * @param T $event
     */
    public function emit(string $event, array $arguments = []): void;
}
