<?php

declare(strict_types=1);

/**
 * Integration check: one workflow signals another over the TrueAsync worker —
 * exercises the codec's SignalExternalWorkflow command (encode
 * SignalExternalWorkflowExecution) and its resolution (decode
 * resolve_signal_external_workflow). Requires the `temporal` extension and a
 * running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_signal_external.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class TrueAsyncSignalReceiverWorkflow
{
    private string $received = '';
    private bool $done = false;

    #[Workflow\SignalMethod(name: 'setValue')]
    public function setValue(string $value): void
    {
        $this->received = $value;
        $this->done = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncSignalReceiverWorkflow')]
    public function handler()
    {
        Workflow::await(fn() => $this->done);

        return 'signal: ' . $this->received;
    }
}

#[Workflow\WorkflowInterface]
class TrueAsyncSignalSenderWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncSignalSenderWorkflow')]
    public function handler(string $targetWorkflowId)
    {
        $stub = Workflow::newUntypedExternalWorkflowStub(new WorkflowExecution($targetWorkflowId));

        $stub->signal('setValue', ['from-external']);

        return 'sent';
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

$taskQueue = 'truasync-sigext-' . \bin2hex(\random_bytes(3));
$receiverId = 'truasync-recv-' . \bin2hex(\random_bytes(4));
$senderId = 'truasync-send-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $receiverId, $senderId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(
        TrueAsyncSignalReceiverWorkflow::class,
        TrueAsyncSignalSenderWorkflow::class,
    );

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));

    // Start the receiver first so the target execution exists when the sender signals.
    $receiver = $client->newUntypedWorkflowStub('TrueAsyncSignalReceiverWorkflow',
        (new WorkflowOptions())->withTaskQueue($taskQueue)->withWorkflowId($receiverId));
    $receiverRun = $client->start($receiver);

    $sender = $client->newUntypedWorkflowStub('TrueAsyncSignalSenderWorkflow',
        (new WorkflowOptions())->withTaskQueue($taskQueue)->withWorkflowId($senderId));
    $senderRun = $client->start($sender, $receiverId);

    $sent = (string) $senderRun->getResult(null, 30);
    $received = (string) $receiverRun->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return ['sent' => $sent, 'received' => $received];
}));

if ($result['sent'] !== 'sent' || $result['received'] !== 'signal: from-external') {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . "\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: signal-external sender={$senderId} -> receiver={$receiverId}, result={$result['received']}\n");
exit(0);
