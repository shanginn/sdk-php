<?php

declare(strict_types=1);

/**
 * Integration check: a signal delivered to a running workflow over the TrueAsync
 * worker. Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_signal.php [address]
 *
 * The workflow blocks on Workflow::await until a signal sets a flag, then returns
 * the signalled value: exercises the codec's signal_workflow -> InvokeSignal path.
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
class TrueAsyncSignalWorkflow
{
    private string $received = '';
    private bool $done = false;

    #[Workflow\SignalMethod(name: 'setValue')]
    public function setValue(string $value): void
    {
        $this->received = $value;
        $this->done = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncSignalWorkflow')]
    public function handler(): iterable
    {
        yield Workflow::await(fn() => $this->done);

        return 'signal: ' . $this->received;
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

$taskQueue = 'truasync-sig-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-sig-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncSignalWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncSignalWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    $stub->signal('setValue', 'hello-signal');

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

if ($result !== 'signal: hello-signal') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} result={$result}\n");
exit(0);
