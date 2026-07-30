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
 * Process resource targets shared by every resource-based supplier in a Worker.
 */
final class ResourceBasedTunerConfig
{
    public function __construct(
        public readonly float $targetMemoryUsage,
        public readonly float $targetCpuUsage,
    ) {
        self::assertUsageTarget($targetMemoryUsage, 'targetMemoryUsage');
        self::assertUsageTarget($targetCpuUsage, 'targetCpuUsage');
    }

    private static function assertUsageTarget(float $value, string $name): void
    {
        \is_finite($value) && $value > 0.0 && $value <= 1.0
            or throw new \InvalidArgumentException(\sprintf(
                '%s must be a finite number greater than 0 and at most 1.',
                $name,
            ));
    }
}
