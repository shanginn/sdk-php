<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\Worker\Environment\EnvironmentInterface;

/**
 * Real time for non-deterministic worker-side request deadlines.
 *
 * @internal
 */
final class WallClockEnvironment implements EnvironmentInterface
{
    public function now(): \DateTimeInterface
    {
        return new \DateTimeImmutable('now');
    }

    public function isReplaying(): bool
    {
        return false;
    }
}
