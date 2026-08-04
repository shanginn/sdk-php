<?php

declare(strict_types=1);

/**
 * Integration check: continue-as-new over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_continue_as_new.php [address]
 *
 * A workflow continues itself as a new run from a child Workflow scope until
 * a counter reaches 3, then completes. This verifies that Continue-As-New is
 * terminal for the whole execution even when the root handler returns while
 * the child scope is suspended on the command.
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

        Workflow::async(
            static fn() => Workflow::continueAsNew('HopWorkflow', [$hop + 1]),
        );

        return 'must-not-complete:' . $hop;
    }
}

#[Workflow\WorkflowInterface]
class SignalHopWorkflow
{
    private bool $finishCurrentRun = false;

    #[Workflow\SignalMethod(name: 'continue')]
    public function continue(): void
    {
        $this->finishCurrentRun = true;
        Workflow::continueAsNew('SignalHopWorkflow', [1]);
    }

    #[WorkflowMethod(name: 'SignalHopWorkflow')]
    public function handler(int $hop): string
    {
        if ($hop >= 1) {
            return 'signal-hops:' . $hop;
        }

        Workflow::await(fn(): bool => $this->finishCurrentRun);
        return 'must-not-complete-signal:' . $hop;
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
$signalWfId = $wfId . '-signal';

[$result, $signalResult] = await(spawn(static function () use (
    $address,
    $taskQueue,
    $wfId,
    $signalWfId,
): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(HopWorkflow::class, SignalHopWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('HopWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, 0);

    $value = (string) $run->getResult(null, 30);

    $signalStub = $client->newUntypedWorkflowStub('SignalHopWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($signalWfId));
    $signalRun = $client->start($signalStub, 0);
    $signalStub->signal('continue');
    $signalValue = (string) $signalRun->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return [$value, $signalValue];
}));

if ($result !== 'hops:3') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

if ($signalResult !== 'signal-hops:1') {
    \fwrite(\STDERR, "FAIL: unexpected signal workflow result: {$signalResult}\n");
    exit(1);
}

\fwrite(
    \STDOUT,
    "PASS: workflow={$wfId} result={$result} signalWorkflow={$signalWfId} signalResult={$signalResult}\n",
);
exit(0);
