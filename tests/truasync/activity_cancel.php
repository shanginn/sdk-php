<?php

declare(strict_types=1);

/**
 * Integration check: cooperative activity cancellation over the Rust-core
 * transport.
 *
 *   php -d extension=temporal.so tests/truasync/activity_cancel.php [address]
 *
 * A long-running activity heartbeats in a loop; shutting the worker down makes
 * the core deliver a cancel-variant activity task (reason: worker shutdown).
 * The loop records it in CoreRpcConnection, the next heartbeat's response flags
 * it, ActivityContext throws ActivityCanceledException inside the activity, and
 * the bubbled exception completes the task as *cancelled* (not failed).
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
use Temporal\Workflow;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\delay;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class CancelProbeWorkflow
{
    #[WorkflowMethod(name: 'CancelProbeWorkflow')]
    public function handler()
    {
        $act = Workflow::newActivityStub(
            CancelProbeActivity::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(60)
                ->withHeartbeatTimeout(20),
        );

        return $act->waitForCancel();
    }
}

#[ActivityInterface(prefix: 'CancelProbeActivity.')]
class CancelProbeActivity
{
    public function __construct(private readonly \stdClass $state) {}

    #[ActivityMethod]
    public function waitForCancel(): string
    {
        $this->state->started = true;

        try {
            for ($i = 0; $i < 200; $i++) {
                delay(100);
                Activity::heartbeat($i);
            }
        } catch (ActivityCanceledException $e) {
            $this->state->canceled = true;
            throw $e;   /* bubble: the completion must report *cancelled* */
        }

        return 'never-canceled';
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

$taskQueue = 'truasync-cancel-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-cancel-' . \bin2hex(\random_bytes(4));

$state = new \stdClass();
$state->started = false;
$state->canceled = false;

$ok = await(spawn(static function () use ($address, $taskQueue, $wfId, $state): bool {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(CancelProbeWorkflow::class);
    $worker->registerActivityImplementations(new CancelProbeActivity($state));

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('CancelProbeWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $client->start($stub);

    /* Wait for the activity to be live and heartbeating, then shut down: the
       core cancels the outstanding activity as part of the shutdown. */
    while (!$state->started) {
        delay(50);
    }
    delay(300);

    $worker->shutdown();
    await($loop);

    return $state->canceled;
}));

if (!$ok) {
    \fwrite(\STDERR, "FAIL: activity finished without observing the cancel\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} activity observed the cancel\n");
exit(0);
