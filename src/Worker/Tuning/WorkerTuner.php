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
 * Provides one slot supplier for every Worker task type.
 */
interface WorkerTuner
{
    public function workflowSlotSupplier(): SlotSupplier;

    public function activitySlotSupplier(): SlotSupplier;

    public function localActivitySlotSupplier(): SlotSupplier;

    public function nexusSlotSupplier(): SlotSupplier;
}
