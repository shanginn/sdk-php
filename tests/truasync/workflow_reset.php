<?php

declare(strict_types=1);

/**
 * Integration check: resetting a workflow over the TrueAsync worker — exercises
 * the codec's update_random_seed handling. When a workflow is reset, the core
 * gives the reset run a fresh random seed, delivered as an update_random_seed
 * activation job before the run re-executes. The reused engine has no core-seeded
 * PRNG to apply it to, so the codec consumes the job as a no-op — but it must
 * consume it: left unhandled it raises and every task of the reset run fails
 * (verified: with the handler removed, the reset run fails with an unhandled
 * error on that job). This test starts a workflow, resets it to its first
 * workflow task, and confirms the reset run still runs to completion.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_reset.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\ResetReapplyType;
use Temporal\Api\Workflowservice\V1\ResetWorkflowExecutionRequest;
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
class TrueAsyncResetWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncResetWorkflow')]
    public function handler(): iterable
    {
        // A timer gives the run a workflow task to reset to, and makes the reset
        // run re-execute (and so receive update_random_seed) before completing.
        yield Workflow::timer(1);

        return 'done';
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

$taskQueue = 'truasync-reset-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-reset-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncResetWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $service = TrueAsyncServiceClient::fromCore(new Connection($address));
    $client = WorkflowClient::create($service);
    $stub = $client->newUntypedWorkflowStub('TrueAsyncResetWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);
    $first = (string) $run->getResult(null, 30);

    // Reset to the first workflow task (event 3 = first WorkflowTaskStarted). The
    // core spins up a new run that replays from scratch with a fresh random seed.
    $reset = $service->ResetWorkflowExecution(
        (new ResetWorkflowExecutionRequest())
            ->setNamespace('default')
            ->setWorkflowExecution(
                (new WorkflowExecution())->setWorkflowId($wfId)->setRunId($run->getExecution()->getRunID()),
            )
            ->setReason('truasync reset test')
            ->setWorkflowTaskFinishEventId(3)
            ->setRequestId(\bin2hex(\random_bytes(8)))
            ->setResetReapplyType(ResetReapplyType::RESET_REAPPLY_TYPE_NONE),
    );

    // The reset run must run to completion despite the update_random_seed job.
    $resetRun = $client->newUntypedRunningWorkflowStub($wfId, $reset->getRunId(), 'TrueAsyncResetWorkflow');
    $second = (string) $resetRun->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return ['first' => $first, 'second' => $second, 'resetRunId' => $reset->getRunId()];
}));

if ($result['first'] !== 'done' || $result['second'] !== 'done') {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . " (wanted both 'done')\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: reset workflow={$wfId} reset-run={$result['resetRunId']} result={$result['second']}\n");
exit(0);
