<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Harness\Update\WorkerRestart;

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Tests\Acceptance\App\Attribute\Stub;
use Temporal\Tests\Acceptance\App\Runtime\SharedStore;
use Temporal\Tests\Acceptance\App\Runtime\WorkerStarter;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

const KV_ACTIVITY_STARTED = 'update-worker-restart-started';
const KV_ACTIVITY_BLOCKED = 'update-worker-restart-blocked';

class WorkerRestartTest extends TestCase
{
    #[Test]
    #[DoesNotPerformAssertions]
    public static function check(
        #[Stub('Harness_Update_WorkerRestart')]WorkflowStubInterface $stub,
        SharedStore $store,
        WorkerStarter $workerStarter,
    ): void {
        $handle = $stub->startUpdate('do_activities');

        # Wait for the activity to start.
        $deadline = \microtime(true) + 20;
        do {
            if ($store->get(KV_ACTIVITY_STARTED, false)) {
                break;
            }

            \microtime(true) > $deadline and throw throw new \RuntimeException('Activity did not start');
            \usleep(100_000);
        } while (true);

        # Restart the worker.
        $workerStarter->stop();
        $workerStarter->start();
        # Unblocks the activity.
        $store->set(KV_ACTIVITY_BLOCKED, false);

        # Wait for Temporal restarts the activity
        $handle->getResult(30);
        $stub->getResult();
    }
}

#[WorkflowInterface]
class FeatureWorkflow
{
    private bool $done = false;

    #[WorkflowMethod('Harness_Update_WorkerRestart')]
    public function run()
    {
        Workflow::await(fn(): bool => $this->done);

        return 'Hello, World!';
    }

    #[Workflow\UpdateMethod('do_activities')]
    public function doActivities(): void
    {
        Workflow::executeActivity(
            'blocks',
            options: ActivityOptions::new()->withStartToCloseTimeout(10),
        );
        $this->done = true;
    }
}

#[ActivityInterface]
class FeatureActivity
{
    public function __construct(
        private SharedStore $store,
    ) {}

    #[ActivityMethod('blocks')]
    public function blocks(): string
    {
        $this->store->set(KV_ACTIVITY_STARTED, true);

        do {
            $blocked = $this->store->get(KV_ACTIVITY_BLOCKED, true);

            if (!$blocked) {
                break;
            }

            \Async\delay(100);
        } while (true);

        return 'hi';
    }
}
