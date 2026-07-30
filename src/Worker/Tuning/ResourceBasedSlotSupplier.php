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
 * Dynamically grants slots based on process memory and CPU usage.
 */
final readonly class ResourceBasedSlotSupplier implements SlotSupplier
{
    public function __construct(
        public ResourceBasedSlotConfig $slotConfig,
        public ResourceBasedTunerConfig $tunerConfig,
    ) {}
}
