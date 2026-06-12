<?php

declare(strict_types=1);

/**
 * Integration check: cancelling a workflow with an in-flight activity, over the
 * Rust-core transport — the full loop across both worker sides.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_cancel_activity.php [address]
 *
 * Client cancel -> cancel_workflow job -> scope cancel -> RequestCancelActivity
 * command -> the core resolves the activity as cancelled for the workflow
 * (TRY_CANCEL) and delivers a cancel-variant activity task -> the running
 * activity observes ActivityCanceledException on its next heartbeat -> the
 * workflow observes the CanceledFailure and completes as CANCELED.
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Activity;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\ActivityCanceledException;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\delay;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class CancelActivityWorkflow
{
    #[WorkflowMethod(name: 'CancelActivityWorkflow')]
    public function handler(): iterable
    {
        /* A short heartbeat timeout matters: the core throttles outbound
           heartbeats to ~0.8x of it, and the server reports cancel-requested
           only in a heartbeat response — a long timeout would delay the
           activity's discovery of the cancel by tens of seconds. */
        $act = Workflow::newActivityStub(
            LingeringActivity::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(120)
                ->withHeartbeatTimeout(2),
        );

        return yield $act->linger();
    }
}

#[ActivityInterface(prefix: 'LingeringActivity.')]
class LingeringActivity
{
    public function __construct(private readonly \stdClass $state) {}

    #[ActivityMethod]
    public function linger(): string
    {
        $this->state->started = true;

        try {
            for ($i = 0; $i < 600; $i++) {
                delay(100);
                Activity::heartbeat($i);
            }
        } catch (ActivityCanceledException $e) {
            $this->state->canceled = true;
            throw $e;
        }

        return 'never-finished';
    }
}

$address = $argv[1] ?? '127.0.0.1:7233';

[$host, $port] = \explode(':', $address) + [1 => '7233'];
$probe = @\fsockopen($host, (int) $port, $errno, $errstr, 1.0);
if ($probe === false) {
    \fwrite(\STDERR, "SKIP: no Temporal server at {$address}\n");
    exit(0);
}
\fclose($probe);

if (!\extension_loaded('temporal')) {
    \fwrite(\STDERR, "FAIL: temporal extension not loaded\n");
    exit(1);
}

$taskQueue = 'truasync-cact-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-cact-' . \bin2hex(\random_bytes(4));

$state = new \stdClass();
$state->started = false;
$state->canceled = false;

$out = await(spawn(static function () use ($address, $taskQueue, $wfId, $state): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(CancelActivityWorkflow::class);
    $worker->registerActivityImplementations(new LingeringActivity($state));

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('CancelActivityWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    while (!$state->started) {
        delay(50);
    }
    delay(300);

    $stub->cancel();

    $workflowCanceled = false;
    try {
        $run->getResult(null, 30);
    } catch (\Throwable $e) {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof CanceledFailure) {
                $workflowCanceled = true;
                break;
            }
        }
    }

    /* The activity notices on its next heartbeat; give it a moment. */
    for ($i = 0; $i < 100 && !$state->canceled; $i++) {
        delay(100);
    }

    $worker->shutdown();
    await($loop);

    return [$workflowCanceled, $state->canceled];
}));

[$workflowCanceled, $activityCanceled] = $out;

if (!$workflowCanceled || !$activityCanceled) {
    \fwrite(\STDERR, 'FAIL: workflowCanceled=' . \var_export($workflowCanceled, true)
        . ' activityCanceled=' . \var_export($activityCanceled, true) . "\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} canceled; in-flight activity observed the cancel\n");
exit(0);
