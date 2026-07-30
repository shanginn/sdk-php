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
 * Applies one resource controller to all Worker task types.
 */
final class ResourceBasedTuner implements WorkerTuner
{
    private readonly CompositeTuner $tuner;

    public function __construct(
        ResourceBasedTunerConfig $tunerConfig,
        ?ResourceBasedSlotConfig $workflowConfig = null,
        ?ResourceBasedSlotConfig $activityConfig = null,
        ?ResourceBasedSlotConfig $localActivityConfig = null,
        ?ResourceBasedSlotConfig $nexusConfig = null,
    ) {
        $this->tuner = new CompositeTuner(
            new ResourceBasedSlotSupplier($workflowConfig ?? new ResourceBasedSlotConfig(), $tunerConfig),
            new ResourceBasedSlotSupplier($activityConfig ?? new ResourceBasedSlotConfig(), $tunerConfig),
            new ResourceBasedSlotSupplier($localActivityConfig ?? new ResourceBasedSlotConfig(), $tunerConfig),
            new ResourceBasedSlotSupplier($nexusConfig ?? new ResourceBasedSlotConfig(), $tunerConfig),
        );
    }

    public function workflowSlotSupplier(): SlotSupplier
    {
        return $this->tuner->workflowSlotSupplier();
    }

    public function activitySlotSupplier(): SlotSupplier
    {
        return $this->tuner->activitySlotSupplier();
    }

    public function localActivitySlotSupplier(): SlotSupplier
    {
        return $this->tuner->localActivitySlotSupplier();
    }

    public function nexusSlotSupplier(): SlotSupplier
    {
        return $this->tuner->nexusSlotSupplier();
    }
}
