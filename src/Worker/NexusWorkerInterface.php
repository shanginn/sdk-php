<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker;

use Temporal\Internal\Declaration\Prototype\NexusServicePrototype;

/**
 * A worker that can register and serve Nexus services.
 */
interface NexusWorkerInterface extends WorkerInterface
{
    /**
     * Register one or multiple Nexus service implementations to be served by this worker.
     *
     * @return $this
     */
    public function registerNexusServiceImplementation(object ...$services): self;

    /**
     * Returns list of registered Nexus service prototypes.
     *
     * @return iterable<NexusServicePrototype>
     */
    public function getNexusServices(): iterable;
}
