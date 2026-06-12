<?php

declare(strict_types=1);

/**
 * Integration check: workflow queries over the Rust-core transport.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_query.php [address]
 *
 * A workflow holds a counter advanced by signals; the client queries it while
 * the workflow is running (query_workflow job -> InvokeQuery -> QueryResult
 * command), including a failure round-trip for an unknown query type, then
 * signals completion.
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
class QueryProbeWorkflow
{
    private int $counter = 0;
    private bool $done = false;

    #[WorkflowMethod(name: 'QueryProbeWorkflow')]
    public function handler(int $start): iterable
    {
        $this->counter = $start;

        yield Workflow::await(fn(): bool => $this->done);

        return 'completed:' . $this->counter;
    }

    #[Workflow\SignalMethod]
    public function bump(int $by): void
    {
        $this->counter += $by;
    }

    #[Workflow\SignalMethod]
    public function finish(): void
    {
        $this->done = true;
    }

    #[Workflow\QueryMethod]
    public function counter(): int
    {
        return $this->counter;
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

$taskQueue = 'truasync-query-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-query-' . \bin2hex(\random_bytes(4));

/** Poll the query until it returns $expected (signal/query ordering is async). */
function poll_counter(object $stub, int $expected): int
{
    for ($i = 0; $i < 100; $i++) {
        $value = (int) $stub->query('counter')->getValue(0);
        if ($value === $expected) {
            return $value;
        }
        delay(100);
    }

    return -1;
}

$out = await(spawn(static function () use ($address, $taskQueue, $wfId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(QueryProbeWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('QueryProbeWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, 7);

    $first = poll_counter($stub, 7);

    $stub->signal('bump', 35);
    $second = poll_counter($stub, 42);

    /* An unknown query type must come back as a query failure, not break the
       worker or fail the workflow task. */
    $unknownFailed = false;
    try {
        $stub->query('no_such_query');
    } catch (\Throwable $e) {
        $unknownFailed = true;
    }

    $stub->signal('finish');
    $result = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return [$first, $second, $unknownFailed, $result];
}));

[$first, $second, $unknownFailed, $result] = $out;

if ($first !== 7 || $second !== 42 || !$unknownFailed || $result !== 'completed:42') {
    \fwrite(\STDERR, "FAIL: first={$first} second={$second} unknownFailed=" .
        \var_export($unknownFailed, true) . " result={$result}\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: workflow={$wfId} counter 7->42, unknown query rejected, result={$result}\n");
exit(0);
