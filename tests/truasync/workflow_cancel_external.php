<?php

declare(strict_types=1);

/**
 * Integration check: one workflow requests cancellation of another over the
 * TrueAsync worker — exercises the codec's CancelExternalWorkflow command
 * (encode RequestCancelExternalWorkflowExecution) and its resolution (decode
 * resolve_request_cancel_external_workflow). Requires the `temporal` extension
 * and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_cancel_external.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class TrueAsyncCancelVictimWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncCancelVictimWorkflow')]
    public function handler()
    {
        try {
            Workflow::timer(3600);

            return 'completed-normally';
        } catch (CanceledFailure $e) {
            // Observed the external cancel at the timer await; finish cleanly so
            // the result is deterministic.
            return 'victim-cancelled';
        }
    }
}

#[Workflow\WorkflowInterface]
class TrueAsyncCancelSenderWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncCancelSenderWorkflow')]
    public function handler(string $targetWorkflowId)
    {
        $stub = Workflow::newUntypedExternalWorkflowStub(new WorkflowExecution($targetWorkflowId));

        $stub->cancel();

        return 'cancel-sent';
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

$taskQueue = 'truasync-cancext-' . \bin2hex(\random_bytes(3));
$victimId = 'truasync-victim-' . \bin2hex(\random_bytes(4));
$senderId = 'truasync-canceller-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $victimId, $senderId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(
        TrueAsyncCancelVictimWorkflow::class,
        TrueAsyncCancelSenderWorkflow::class,
    );

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));

    // Start the victim (a long timer) so the target exists, then cancel it.
    $victim = $client->newUntypedWorkflowStub('TrueAsyncCancelVictimWorkflow',
        (new WorkflowOptions())->withTaskQueue($taskQueue)->withWorkflowId($victimId));
    $victimRun = $client->start($victim);

    $sender = $client->newUntypedWorkflowStub('TrueAsyncCancelSenderWorkflow',
        (new WorkflowOptions())->withTaskQueue($taskQueue)->withWorkflowId($senderId));
    $senderRun = $client->start($sender, $victimId);

    $sent = (string) $senderRun->getResult(null, 30);
    $victimOutcome = (string) $victimRun->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return ['sent' => $sent, 'victim' => $victimOutcome];
}));

if ($result['sent'] !== 'cancel-sent' || $result['victim'] !== 'victim-cancelled') {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . "\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: cancel-external canceller={$senderId} -> victim={$victimId} ({$result['victim']})\n");
exit(0);
