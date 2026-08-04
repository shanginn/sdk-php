<?php

declare(strict_types=1);

/**
 * Integration check: child workflows over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_child.php [address]
 *
 * The parent starts a child on the same task queue (StartChildWorkflowExecution
 * with a deterministic default child id), awaits its start confirmation
 * (resolve_child_workflow_execution_start -> the GetChildWorkflowExecution
 * waiter) and its result (resolve_child_workflow_execution), and returns a
 * value derived from both.
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Workflow;
use Temporal\Workflow\ChildWorkflowOptions;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class ChildSumWorkflow
{
    #[WorkflowMethod(name: 'ChildSumWorkflow')]
    public function handler(int $a, int $b): int
    {
        return $a + $b;
    }
}

#[Workflow\WorkflowInterface]
class ParentWorkflow
{
    #[WorkflowMethod(name: 'ParentWorkflow')]
    public function handler()
    {
        $child = Workflow::newChildWorkflowStub(
            ChildSumWorkflow::class,
            ChildWorkflowOptions::new()->withTaskQueue(Workflow::getInfo()->taskQueue),
        );

        $sum = $child->handler(40, 2);

        return 'child-sum:' . $sum;
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

$taskQueue = 'truasync-child-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-child-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(ParentWorkflow::class, ChildSumWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('ParentWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

if ($result !== 'child-sum:42') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} result={$result}\n");
exit(0);
