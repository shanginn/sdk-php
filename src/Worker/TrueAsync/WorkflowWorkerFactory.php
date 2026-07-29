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
 * Engine-only compatibility alias used by {@see TemporalWorker}.
 *
 * Normal applications should use {@see \Temporal\WorkerFactory}; it now owns
 * the native core worker lifecycle directly.
 *
 * @internal
 */
final class WorkflowWorkerFactory extends \Temporal\WorkerFactory {}
