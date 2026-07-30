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
 * Supplies execution slots for one Worker task type.
 *
 * Custom callback suppliers are intentionally not part of the TrueAsync
 * transport yet. Use {@see FixedSizeSlotSupplier} or
 * {@see ResourceBasedSlotSupplier}.
 */
interface SlotSupplier {}
