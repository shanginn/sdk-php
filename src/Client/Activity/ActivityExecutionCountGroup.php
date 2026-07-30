<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

final class ActivityExecutionCountGroup
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(
        public readonly array $values,
        public readonly int $count,
    ) {}
}
