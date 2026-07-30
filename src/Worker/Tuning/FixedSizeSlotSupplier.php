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
 * A slot supplier that permits at most a fixed number of concurrent tasks.
 */
final readonly class FixedSizeSlotSupplier implements SlotSupplier
{
    public function __construct(
        public int $slots,
    ) {
        $slots > 0 or throw new \InvalidArgumentException(
            'Fixed-size slot supplier requires at least one slot.',
        );
    }
}
