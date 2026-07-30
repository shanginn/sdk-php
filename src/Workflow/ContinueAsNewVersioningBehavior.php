<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Workflow;

/**
 * Specifies the versioning behavior used for the first Workflow Task of a run
 * created by Continue-As-New.
 *
 * @see \Temporal\Api\Enums\V1\ContinueAsNewVersioningBehavior
 *
 * @internal ExperimentalAPI
 */
enum ContinueAsNewVersioningBehavior: int
{
    /**
     * Inherit the behavior of the previous run. An AutoUpgrade run remains
     * AutoUpgrade; a Pinned run remains pinned to the same Deployment Version.
     */
    case Unspecified = 0;

    /**
     * Start the new run as AutoUpgrade on the Task Queue's Target Version.
     *
     * After its first Workflow Task, the new run uses the behavior declared by
     * its Workflow code. A Pinned Versioning Override still takes precedence.
     */
    case AutoUpgrade = 1;

    /**
     * Start the new run on the Task Queue's Ramping Version selected for this
     * Workflow ID, falling back to the Current Version when no ramp is active.
     *
     * This only affects the first Workflow Task. A Pinned Versioning Override
     * still takes precedence.
     */
    case UseRampingVersion = 2;
}
