<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Transport;

use Temporal\Promise;
use Temporal\Worker\Transport\Command\CommandInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow\WorkflowContextInterface;

interface ClientInterface
{
    /**
     * Send a request and return a promise.
     * 
     * @return Promise<mixed>
     */
    public function request(RequestInterface $request, ?WorkflowContextInterface $context = null): Promise;

    /**
     * Send a request without tracking the response.
     */
    public function send(CommandInterface $request): void;

    /**
     * Check if command still in sending queue.
     */
    public function isQueued(CommandInterface $command): bool;

    /**
     * Cancel a sent command.
     */
    public function cancel(CommandInterface $command): void;

    /**
     * Reject pending promise.
     */
    public function reject(CommandInterface $command, \Throwable $reason): void;
}