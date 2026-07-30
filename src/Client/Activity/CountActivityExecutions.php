<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

final class CountActivityExecutions
{
    /**
     * @param list<ActivityExecutionCountGroup> $groups
     */
    public function __construct(
        public readonly int $count,
        public readonly array $groups = [],
    ) {}
}
