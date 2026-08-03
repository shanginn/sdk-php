<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright information view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Interceptor;

use Temporal\Internal\Interceptor\Interceptor;

/**
 * Wraps construction and execution of one Activity attempt.
 *
 * This lifecycle boundary is useful for request-scoped dependency injection,
 * tracing resources and other state which must exist before the Activity
 * factory runs and be released after its handler returns.
 */
interface ActivityExecutionInterceptor extends Interceptor
{
    /**
     * @param callable(): mixed $next
     */
    public function executeActivity(callable $next): mixed;
}
