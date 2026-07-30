<?php

declare(strict_types=1);

namespace Temporal\Common;

/**
 * Defines how a standalone Activity start resolves an ID conflict with a running Activity.
 *
 * @see \Temporal\Api\Enums\V1\ActivityIdConflictPolicy
 * @experimental Standalone Activities are a Temporal Server Public Preview feature.
 */
enum ActivityIdConflictPolicy: int
{
    case Unspecified = 0;
    case Fail = 1;
    case UseExisting = 2;
}
