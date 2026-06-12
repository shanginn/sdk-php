<?php

declare(strict_types=1);

/**
 * Integration check: the TrueAsync workflow + activity worker over the Rust-core
 * transport (no gRPC, no RoadRunner). Requires the `temporal` extension and a
 * running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_worker.php [address]
 *
 * Drives a timer -> activity -> timer -> activity -> complete workflow, which
 * exercises the codec's command/resolution correlation across several seq-bearing
 * commands. Correlation uses a deterministic per-run seq (not the SDK's global
 * command id), so the workflow completes even when the core replays it from
 * scratch on every task (cache eviction, worker restart, sticky timeout).
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class TrueAsyncReplayWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncReplayWorkflow')]
    public function handler(string $input): iterable
    {
        $act = Workflow::newActivityStub(
            TrueAsyncReplayActivity::class,
            ActivityOptions::new()->withStartToCloseTimeout(10),
        );

        yield Workflow::timer(1);
        $a = yield $act->upper($input);
        yield Workflow::timer(1);
        $b = yield $act->upper($a . '-2');

        return 'done: ' . $a . '/' . $b;
    }
}

#[ActivityInterface(prefix: 'TrueAsyncReplayActivity.')]
class TrueAsyncReplayActivity
{
    #[ActivityMethod]
    public function upper(string $input): string
    {
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

$taskQueue = 'truasync-wf-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-wf-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncReplayWorkflow::class);
    $worker->registerActivityImplementations(new TrueAsyncReplayActivity());

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncReplayWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, 'hello');

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

if ($result !== 'done: HELLO/HELLO-2') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} result={$result}\n");
exit(0);
