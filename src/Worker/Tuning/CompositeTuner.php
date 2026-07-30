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
 * Combines independent slot suppliers for each Worker task type.
 */
final class CompositeTuner implements WorkerTuner
{
    public function __construct(
        private readonly SlotSupplier $workflowSlotSupplier,
        private readonly SlotSupplier $activitySlotSupplier,
        private readonly SlotSupplier $localActivitySlotSupplier,
        private readonly SlotSupplier $nexusSlotSupplier,
    ) {
        $resourceConfig = null;
        foreach ([
            $workflowSlotSupplier,
            $activitySlotSupplier,
            $localActivitySlotSupplier,
            $nexusSlotSupplier,
        ] as $supplier) {
            if (!$supplier instanceof ResourceBasedSlotSupplier) {
                continue;
            }

            if ($resourceConfig === null) {
                $resourceConfig = $supplier->tunerConfig;
                continue;
            }

            $resourceConfig == $supplier->tunerConfig
                or throw new \InvalidArgumentException(
                    'All resource-based slot suppliers in a Worker must use the same tuner configuration.',
                );
        }
    }

    public function workflowSlotSupplier(): SlotSupplier
    {
        return $this->workflowSlotSupplier;
    }

    public function activitySlotSupplier(): SlotSupplier
    {
        return $this->activitySlotSupplier;
    }

    public function localActivitySlotSupplier(): SlotSupplier
    {
        return $this->localActivitySlotSupplier;
    }

    public function nexusSlotSupplier(): SlotSupplier
    {
        return $this->nexusSlotSupplier;
    }
}
