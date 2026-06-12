<?php

declare(strict_types=1);

/**
 * Integration check: the determinism guard over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_determinism_guard.php [address]
 *
 * A workflow calls Async\delay() directly — forbidden: workflow code must
 * never reach the real reactor. The guard detects the suspension (a sentinel
 * coroutine that can only run if the worker coroutine yields), fails the
 * workflow task with NonDeterministicWorkflowException instead of committing
 * the result, and the failure (with its message) lands in the workflow
 * history, where the server retries the task.
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
use function Async\delay;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class NaughtyWorkflow
{
    #[WorkflowMethod(name: 'NaughtyWorkflow')]
    public function handler(): string
    {
        delay(30);   /* the real reactor — non-deterministic, must be caught */

        return 'must-never-complete';
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

$taskQueue = 'truasync-guard-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-guard-' . \bin2hex(\random_bytes(4));

$out = await(spawn(static function () use ($address, $taskQueue, $wfId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(NaughtyWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('NaughtyWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    /* Give the worker a few task attempts, then read the history. */
    delay(3000);

    $guardMessage = null;
    $completed = false;
    $history = $client->getWorkflowHistory($run->getExecution());
    foreach ($history as $event) {
        $failed = $event->getWorkflowTaskFailedEventAttributes();
        if ($failed !== null && $failed->getFailure() !== null) {
            $message = $failed->getFailure()->getMessage();
            if (\str_contains($message, 'suspended the worker coroutine')) {
                $guardMessage = $message;
            }
        }
        if ($event->getWorkflowExecutionCompletedEventAttributes() !== null) {
            $completed = true;
        }
    }

    $stub->terminate('determinism-guard test cleanup');

    $worker->shutdown();
    await($loop);

    return [$guardMessage !== null, $completed];
}));

[$guardFired, $completed] = $out;

if (!$guardFired || $completed) {
    \fwrite(\STDERR, 'FAIL: guardFired=' . \var_export($guardFired, true)
        . ' completed=' . \var_export($completed, true) . "\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} task failed with the guard message; result was not committed\n");
exit(0);
