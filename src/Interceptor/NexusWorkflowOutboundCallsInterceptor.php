<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Interceptor;

use React\Promise\PromiseInterface;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteNexusOperationInput;

/**
 * Workflow outbound-calls interceptor extension for Nexus operations.
 *
 * This additive interface preserves compatibility for existing
 * {@see WorkflowOutboundCallsInterceptor} implementations.
 */
interface NexusWorkflowOutboundCallsInterceptor extends WorkflowOutboundCallsInterceptor
{
    /**
     * @param callable(ExecuteNexusOperationInput): PromiseInterface $next
     */
    public function executeNexusOperation(ExecuteNexusOperationInput $input, callable $next): PromiseInterface;
}
