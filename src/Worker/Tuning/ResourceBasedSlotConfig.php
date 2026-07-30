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
 * Resource-based limits for one task type.
 *
 * Null values use Core's language-SDK defaults: a minimum of 5 and no ramp
 * delay for Workflows; a minimum of 1 and a 50 ms ramp delay for Activities,
 * Local Activities, and Nexus tasks; and a maximum of 500 for every task type.
 */
final readonly class ResourceBasedSlotConfig
{
    public function __construct(
        public ?int $minimumSlots = null,
        public ?int $maximumSlots = null,
        public ?int $rampThrottleMs = null,
    ) {
        ($minimumSlots === null || $minimumSlots > 0)
            or throw new \InvalidArgumentException('minimumSlots must be greater than 0.');
        ($maximumSlots === null || $maximumSlots > 0)
            or throw new \InvalidArgumentException('maximumSlots must be greater than 0.');
        ($rampThrottleMs === null || $rampThrottleMs >= 0)
            or throw new \InvalidArgumentException('rampThrottleMs must be non-negative.');
        ($minimumSlots === null || $maximumSlots === null || $maximumSlots >= $minimumSlots)
            or throw new \InvalidArgumentException('maximumSlots must be greater than or equal to minimumSlots.');
    }
}
