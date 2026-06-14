<?php

declare(strict_types=1);

/**
 * Integration check: Workflow Update over the TrueAsync worker — exercises the
 * codec's do_update job (-> InvokeUpdate) and the UpdateResponse commands routed
 * back through the factory (validate -> accept/reject, then complete). Covers an
 * accepted+completed update (state mutated, result returned) and a validator
 * rejection. Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_update.php [address]
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
class TrueAsyncUpdateWorkflow
{
    private int $total = 0;
    private bool $done = false;

    #[Workflow\UpdateMethod(name: 'add')]
    public function add(int $n): int
    {
        $this->total += $n;

        return $this->total;
    }

    #[Workflow\UpdateValidatorMethod(forUpdate: 'add')]
    public function validateAdd(int $n): void
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('n must be non-negative');
        }
    }

    #[Workflow\SignalMethod(name: 'finish')]
    public function finish(): void
    {
        $this->done = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncUpdateWorkflow')]
    public function handler(): iterable
    {
        yield Workflow::await(fn() => $this->done);

        return 'total: ' . $this->total;
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

$taskQueue = 'truasync-update-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-update-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncUpdateWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncUpdateWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    // Accepted + completed: each update mutates state and returns the new total.
    $first = (int) $stub->update('add', 5)->getValue(0);
    $second = (int) $stub->update('add', 37)->getValue(0);

    // Rejected by the validator (negative) — must throw client-side and NOT
    // change state.
    $rejected = false;
    try {
        $stub->update('add', -1);
    } catch (\Throwable $e) {
        $rejected = true;
    }

    $stub->signal('finish');
    $final = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return ['first' => $first, 'second' => $second, 'rejected' => $rejected, 'final' => $final];
}));

if ($result['first'] !== 5 || $result['second'] !== 42
    || $result['rejected'] !== true || $result['final'] !== 'total: 42') {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . "\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: update workflow={$wfId} 5->{$result['first']}, +37->{$result['second']}, "
    . "rejected-negative={$result['rejected']}, result={$result['final']}\n");
exit(0);
