<?php

declare(strict_types=1);

/**
 * Integration check: activity heartbeats over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/activity_heartbeat.php [address]
 *
 * Proves the full heartbeat path — Activity::heartbeat() -> CoreRpcConnection ->
 * Core\Worker::recordActivityHeartbeat -> core -> server — by round-tripping the
 * details: attempt 1 heartbeats a progress marker and fails; the server hands the
 * marker back on the retry, where attempt 2 recovers it via
 * Activity::getHeartbeatDetails() and returns it as the workflow result.
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Carbon\CarbonInterval;
use Temporal\Activity;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class HeartbeatWorkflow
{
    #[WorkflowMethod(name: 'HeartbeatWorkflow')]
    public function handler(string $input): iterable
    {
        $act = Workflow::newActivityStub(
            HeartbeatActivity::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(10)
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withInitialInterval(CarbonInterval::second(1))
                        ->withMaximumAttempts(3),
                ),
        );

        return yield $act->step($input);
    }
}

#[ActivityInterface(prefix: 'HeartbeatActivity.')]
class HeartbeatActivity
{
    #[ActivityMethod]
    public function step(string $input): string
    {
        if (Activity::hasHeartbeatDetails()) {
            /* Attempt 2+: the server delivered the previous attempt's details. */
            return 'recovered:' . Activity::getHeartbeatDetails();
        }

        Activity::heartbeat('progress-' . $input);

        /* Give the core a moment to ship the heartbeat before the failure
           closes this attempt (recording is async on the core's threads). */
        \Async\delay(300);

        throw new \RuntimeException('attempt 1 fails after heartbeating');
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

$taskQueue = 'truasync-hb-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-hb-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(HeartbeatWorkflow::class);
    $worker->registerActivityImplementations(new HeartbeatActivity());

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('HeartbeatWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, 'hello');

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

if ($result !== 'recovered:progress-hello') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} result={$result}\n");
exit(0);
