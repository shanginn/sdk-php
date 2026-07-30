<?php

declare(strict_types=1);

namespace Temporal\Common;

/**
 * Defines whether a closed standalone Activity ID may be reused.
 *
 * @see \Temporal\Api\Enums\V1\ActivityIdReusePolicy
 * @experimental Standalone Activities are a Temporal Server Public Preview feature.
 */
enum ActivityIdReusePolicy: int
{
    case Unspecified = 0;
    case AllowDuplicate = 1;
    case AllowDuplicateFailedOnly = 2;
    case RejectDuplicate = 3;
}
