<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

/**
 * Narrow Core surface used by the Nexus poll loop.
 *
 * The native extension's final Core Worker satisfies the same method contract.
 * This interface keeps cancellation and shutdown races deterministic in unit
 * tests without replacing the production transport.
 *
 * @internal
 */
interface NexusCoreWorkerInterface
{
    public function pollNexusTask(): ?string;

    public function completeNexusTask(string $completion): void;

    public function initiateShutdown(): void;
}
