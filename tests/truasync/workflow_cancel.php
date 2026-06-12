<?php

declare(strict_types=1);

/**
 * Integration check: workflow cancellation over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_cancel.php [address]
 *
 * Two scenarios:
 *
 * 1. The client cancels a workflow parked on a plain timer. The core resolves
 *    nothing for a cancelled timer, so this exercises the synthesized
 *    rejection path end to end: cancel_workflow -> scope cancel -> CancelTimer
 *    command + local CanceledFailure -> CancelWorkflowExecution, all within
 *    one activation. (Without the synthesis this would hang forever.)
 *
 * 2. awaitWithTimeout where the condition (a signal) wins the race: the losing
 *    internal timer is cancelled mid-run and the workflow keeps going —
 *    proving CancelTimer on a live run is benign.
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
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
class TimerParkWorkflow
{
    #[WorkflowMethod(name: 'TimerParkWorkflow')]
    public function handler(): iterable
    {
        yield Workflow::timer(300);

        return 'timer-fired';
    }
}

#[Workflow\WorkflowInterface]
class TimeoutRaceWorkflow
{
    private bool $go = false;

    #[WorkflowMethod(name: 'TimeoutRaceWorkflow')]
    public function handler(): iterable
    {
        $signaled = yield Workflow::awaitWithTimeout(60, fn(): bool => $this->go);

        return $signaled ? 'signaled' : 'timed-out';
    }

    #[Workflow\SignalMethod]
    public function go(): void
    {
        $this->go = true;
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

$taskQueue = 'truasync-cwf-' . \bin2hex(\random_bytes(3));

/** True when the exception chain contains a CanceledFailure. */
function chain_has_cancel(\Throwable $e): bool
{
    for (; $e !== null; $e = $e->getPrevious()) {
        if ($e instanceof CanceledFailure) {
            return true;
        }
    }

    return false;
}

$out = await(spawn(static function () use ($address, $taskQueue): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TimerParkWorkflow::class, TimeoutRaceWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));

    /* 1: cancel while parked on a timer. */
    $stub1 = $client->newUntypedWorkflowStub('TimerParkWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId('truasync-cwf-timer-' . \bin2hex(\random_bytes(4))));
    $run1 = $client->start($stub1);

    delay(500);   /* let the first workflow task complete (timer started) */
    $stub1->cancel();

    $canceled = false;
    try {
        $run1->getResult(null, 30);
    } catch (\Throwable $e) {
        $canceled = chain_has_cancel($e);
    }

    /* 2: awaitWithTimeout, signal wins; the losing timer is cancelled. */
    $stub2 = $client->newUntypedWorkflowStub('TimeoutRaceWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId('truasync-cwf-race-' . \bin2hex(\random_bytes(4))));
    $run2 = $client->start($stub2);

    delay(500);
    $stub2->signal('go');
    $raceResult = (string) $run2->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return [$canceled, $raceResult];
}));

[$canceled, $raceResult] = $out;

if (!$canceled || $raceResult !== 'signaled') {
    \fwrite(\STDERR, 'FAIL: canceled=' . \var_export($canceled, true) . " race={$raceResult}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: timer-parked workflow canceled, awaitWithTimeout race continued ({$raceResult})\n");
exit(0);
