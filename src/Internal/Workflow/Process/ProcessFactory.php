<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Workflow\Process;

use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Workflow\Process\Fiber\Process as FiberProcess;
use Temporal\Internal\Workflow\WorkflowContext;
use Temporal\Workflow\ProcessInterface;

/**
 * Factory for creating appropriate process implementation based on the environment.
 */
class ProcessFactory
{
    /**
     * @var bool Flag to use fiber implementation.
     */
    private static bool $useFibers = false;
    
    /**
     * Set whether to use fiber-based implementation.
     * 
     * @param bool $useFibers
     */
    public static function useFibers(bool $useFibers): void
    {
        self::$useFibers = $useFibers && \PHP_VERSION_ID >= 80100; // Fibers require PHP 8.1+
    }
    
    /**
     * Create a new process implementation.
     * 
     * @param ServiceContainer $container
     * @param WorkflowContext $ctx
     * @param string $runId
     * @return ProcessInterface
     */
    public static function create(
        ServiceContainer $container,
        WorkflowContext $ctx,
        string $runId
    ): ProcessInterface {
        if (self::$useFibers) {
            return new FiberProcess($container, $ctx, $runId);
        }
        
        return new Process($container, $ctx, $runId);
    }
}