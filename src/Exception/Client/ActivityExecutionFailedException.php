<?php

declare(strict_types=1);

namespace Temporal\Exception\Client;

use Temporal\Exception\Failure\TemporalFailure;
use Temporal\Exception\TemporalException;

final class ActivityExecutionFailedException extends TemporalException
{
    public function __construct(
        public readonly string $activityId,
        public readonly string $runId,
        public readonly TemporalFailure $failure,
    ) {
        parent::__construct(
            self::buildMessage([
                'activityId' => $activityId,
                'runId' => $runId,
            ]),
            previous: $failure,
        );
    }
}
