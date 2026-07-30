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
 * Explains why the Temporal Service suggests Continue-As-New.
 *
 * Multiple reasons may be reported for the same Workflow Task.
 *
 * @see \Temporal\Api\Enums\V1\SuggestContinueAsNewReason
 *
 * @internal ExperimentalAPI
 */
enum ContinueAsNewSuggestedReason: int
{
    case Unspecified = 0;

    /**
     * Workflow history size is approaching the configured limit.
     */
    case HistorySizeTooLarge = 1;

    /**
     * Workflow history event count is approaching the configured limit.
     */
    case TooManyHistoryEvents = 2;

    /**
     * Completed and in-flight Workflow Updates are approaching the limit.
     */
    case TooManyUpdates = 3;
}
