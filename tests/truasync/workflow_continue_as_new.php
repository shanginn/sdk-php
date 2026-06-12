<?php

declare(strict_types=1);

/**
 * Integration check: continue-as-new over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_continue_as_new.php [address]
 *
 * A workflow continues itself as a new run until a counter reaches 3, then
 * completes. The handler returns the never-resolving continueAsNew promise,
 * so the completion's terminal command is ContinueAsNewWorkflowExecution; the
 * client's getResult follows the run chain to the final value.
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

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
class HopWorkflow
{
    #[WorkflowMethod(name: 'HopWorkflow')]
    public function handler(int $hop)
    {
        if ($hop >= 3) {
            return 'hops:' . $hop;
        }

        return Workflow::continueAsNew('HopWorkflow', [$hop + 1]);
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

$taskQueue = 'truasync-can-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-can-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(HopWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('HopWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, 0);

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

if ($result !== 'hops:3') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} result={$result}\n");
exit(0);
