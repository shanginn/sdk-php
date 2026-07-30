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
 * Scale concurrent long polls using feedback from the Temporal Server.
 */
final class PollerBehaviorAutoscaling implements PollerBehavior
{
    public function __construct(
        public readonly int $minimum = 1,
        public readonly int $maximum = 100,
        public readonly int $initial = 5,
    ) {
        $minimum > 0 or throw new \InvalidArgumentException(
            'Autoscaling poller minimum must be greater than 0.',
        );
        $maximum >= $minimum or throw new \InvalidArgumentException(
            'Autoscaling poller maximum must be greater than or equal to minimum.',
        );
        $initial >= $minimum && $initial <= $maximum
            or throw new \InvalidArgumentException(
                'Autoscaling poller initial value must be between minimum and maximum.',
            );
    }
}
