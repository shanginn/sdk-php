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
 * Workflow code reached the real reactor (direct Async\* usage, blocking I/O,
 * a non-workflow await) while an activation was being processed. Raised by the
 * determinism guard; fails the workflow task instead of committing
 * non-deterministic results.
 */
final class NonDeterministicWorkflowException extends \RuntimeException {}
