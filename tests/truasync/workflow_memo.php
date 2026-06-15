<?php

declare(strict_types=1);

/**
 * Integration check: a workflow upserts its memo over the TrueAsync worker —
 * exercises the codec's UpsertMemo command (encode ModifyWorkflowProperties with
 * an upserted_memo). Verifies the memo via DescribeWorkflowExecution. Memo is
 * freeform, so (unlike search attributes) no registration is needed.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_memo.php [address]
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
class TrueAsyncMemoWorkflow
{
    private bool $done = false;

    #[Workflow\SignalMethod(name: 'finish')]
    public function finish(): void
    {
        $this->done = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncMemoWorkflow')]
    public function handler(string $value): iterable
    {
        Workflow::upsertMemo(['note' => $value, 'count' => 7]);

        yield Workflow::await(fn() => $this->done);

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

$taskQueue = 'truasync-memo-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-memo-' . \bin2hex(\random_bytes(4));
$note = 'memo-' . \bin2hex(\random_bytes(3));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId, $note): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncMemoWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncMemoWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, $note);

    // Drive to completion so the upsert is durably applied, then read it back
    // from DescribeWorkflowExecution (mutable state, no visibility lag).
    $stub->signal('finish');
    $final = (string) $run->getResult(null, 30);

    $description = $stub->describe();
    $memo = $description->info->memo;

    $worker->shutdown();
    await($loop);

    return ['final' => $final, 'note' => $memo->getValue('note'), 'count' => $memo->getValue('count')];
}));

if ($result['final'] !== 'done' || $result['note'] !== $note || $result['count'] !== 7) {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . " (wanted note={$note}, count=7)\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: memo workflow={$wfId} note={$result['note']} count={$result['count']}\n");
exit(0);
