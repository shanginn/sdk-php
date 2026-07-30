<?php

declare(strict_types=1);

namespace Temporal\Exception\Client;

use Temporal\Exception\TemporalException;

final class ActivityExecutionNotFoundException extends TemporalException
{
    public function __construct(
        public readonly string $activityId,
        public readonly ?string $runId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            self::buildMessage([
                'activityId' => $activityId,
                'runId' => $runId,
            ]),
            previous: $previous,
        );
    }
}
