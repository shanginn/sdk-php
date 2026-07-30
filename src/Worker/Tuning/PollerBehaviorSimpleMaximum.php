<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\Tuning;

/**
 * Poll while a task slot is available, up to a fixed concurrent maximum.
 */
final class PollerBehaviorSimpleMaximum implements PollerBehavior
{
    public function __construct(
        public readonly int $maximum = 5,
    ) {
        $maximum > 0 or throw new \InvalidArgumentException(
            'Poller maximum must be greater than 0.',
        );
    }
}
