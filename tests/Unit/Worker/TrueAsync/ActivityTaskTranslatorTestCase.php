<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\TrueAsync;

use Coresdk\ActivityTask\ActivityTask;
use Coresdk\ActivityTask\Start;
use Google\Protobuf\Duration;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Priority;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\DataConverter\DataConverter;
use Temporal\Worker\TrueAsync\ActivityTaskTranslator;

final class ActivityTaskTranslatorTestCase extends TestCase
{
    public function testTaskInfoIncludesRetryPolicyAndPriority(): void
    {
        $task = (new ActivityTask())
            ->setTaskToken('task-token')
            ->setStart(
                (new Start())
                    ->setActivityId('activity-id')
                    ->setActivityType('ExampleActivity')
                    ->setWorkflowNamespace('namespace')
                    ->setWorkflowType('ExampleWorkflow')
                    ->setRetryPolicy(
                        (new RetryPolicy())
                            ->setInitialInterval((new Duration())->setSeconds(1))
                            ->setBackoffCoefficient(3)
                            ->setMaximumInterval((new Duration())->setSeconds(120))
                            ->setMaximumAttempts(20)
                            ->setNonRetryableErrorTypes(['FatalError']),
                    )
                    ->setPriority(
                        (new Priority())
                            ->setPriorityKey(2)
                            ->setFairnessKey('tenant')
                            ->setFairnessWeight(5.4),
                    ),
            );

        $request = (new ActivityTaskTranslator(
            DataConverter::createDefault(),
            'task-queue',
        ))->toServerRequest($task);

        self::assertNotNull($request);
        $info = $request->getOptions()['info'];
        self::assertSame([
            'initial_interval' => ['seconds' => 1, 'nanos' => 0],
            'backoff_coefficient' => 3.0,
            'maximum_interval' => ['seconds' => 120, 'nanos' => 0],
            'maximum_attempts' => 20,
            'non_retryable_error_types' => ['FatalError'],
        ], $info['RetryPolicy']);
        self::assertSame([
            'PriorityKey' => 2,
            'FairnessKey' => 'tenant',
            'FairnessWeight' => 5.4,
        ], $info['Priority']);
    }
}
