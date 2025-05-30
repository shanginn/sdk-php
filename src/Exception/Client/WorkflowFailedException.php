<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Exception\Client;

use Temporal\Workflow\WorkflowExecution;

class WorkflowFailedException extends WorkflowException
{
    private int $lastWorkflowTaskCompletedEventId;

    /**
     * @param non-empty-string|null $type
     */
    public function __construct(
        WorkflowExecution $execution,
        ?string $type,
        int $lastWorkflowTaskCompletedEventId,
        int $retryState,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(null, $execution, $type, $previous);

        $this->message = self::buildMessage(
            [
                'workflowId' => $execution->getID(),
                'runId' => $execution->getRunID(),
                'workflowType' => $type,
                'workflowTaskCompletedEventId' => $lastWorkflowTaskCompletedEventId,
                'retryState' => $retryState,
            ],
        );

        $this->lastWorkflowTaskCompletedEventId = $lastWorkflowTaskCompletedEventId;
    }

    public function getWorkflowTaskCompletedEventId(): int
    {
        return $this->lastWorkflowTaskCompletedEventId;
    }
}
