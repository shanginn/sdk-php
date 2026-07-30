<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

use Temporal\Client\Common\ClientContextInterface;
use Temporal\Client\Common\Paginator;

/**
 * Client for starting and managing standalone Activities.
 *
 * @experimental Requires Temporal Server 1.31+ with Standalone Activities enabled.
 */
interface ActivityClientInterface extends ClientContextInterface
{
    public function start(
        string $activityType,
        ActivityOptions $options,
        mixed ...$arguments,
    ): ActivityHandleInterface;

    public function execute(
        string $activityType,
        ActivityOptions $options,
        mixed ...$arguments,
    ): mixed;

    public function getHandle(
        string $activityId,
        ?string $runId = null,
        ?string $namespace = null,
    ): ActivityHandleInterface;

    /**
     * @return Paginator<ActivityExecutionInfo>
     */
    public function list(
        string $query = '',
        ?string $namespace = null,
        int $pageSize = 100,
    ): Paginator;

    public function count(string $query = '', ?string $namespace = null): CountActivityExecutions;
}
