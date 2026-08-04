<?php

declare(strict_types=1);

/**
 * Integration check: a workflow runs *local* activities over the TrueAsync worker
 * — exercises the codec's ExecuteLocalActivity command (encode
 * ScheduleLocalActivity). A local activity runs in-process and is recorded as a
 * marker rather than dispatched to the server, but the core delivers it through
 * the same activity-task channel and resolves it through the same resolve_activity
 * job, so only the encode is special. The first execution fails once with a retry
 * delay above localRetryThreshold; that forces Core's DoBackoff response and
 * verifies that the codec drives the deterministic timer + reschedule state
 * machine before running a second local activity.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_local_activity.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\LocalActivityOptions;
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
class TrueAsyncLocalActivityWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncLocalActivityWorkflow')]
    public function handler(string $input)
    {
        // Passing LocalActivityOptions routes executeActivity down the local path
        // (the typed-stub proxy instead keys off a #[LocalActivityInterface] attr).
        $options = LocalActivityOptions::new()
            ->withStartToCloseTimeout(10)
            ->withLocalRetryThreshold('10 milliseconds')
            ->withRetryOptions(
                RetryOptions::new()
                    ->withInitialInterval(1)
                    ->withMaximumAttempts(2),
            );

        $a = Workflow::executeActivity('TrueAsyncLocalActivity.upper', [$input], $options);
        $b = Workflow::executeActivity('TrueAsyncLocalActivity.upper', [$a . '-2'], $options);

        return 'local: ' . $a . '/' . $b;
    }
}

#[ActivityInterface(prefix: 'TrueAsyncLocalActivity.')]
class TrueAsyncLocalActivity
{
    /** @var array<string, int> */
    private array $attempts = [];

    #[ActivityMethod]
    public function upper(string $input): string
    {
        $this->attempts[$input] = ($this->attempts[$input] ?? 0) + 1;
        if ($input === 'hello' && $this->attempts[$input] === 1) {
            throw new \RuntimeException('retry me through a workflow timer');
        }

        return \strtoupper($input);
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

$taskQueue = 'truasync-la-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-la-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncLocalActivityWorkflow::class);
    $worker->registerActivityImplementations(new TrueAsyncLocalActivity());

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncLocalActivityWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, 'hello');

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

if ($result !== 'local: HELLO/HELLO-2') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: local-activity workflow={$wfId} result={$result}\n");
exit(0);
