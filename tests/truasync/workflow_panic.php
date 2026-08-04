<?php

declare(strict_types=1);

/**
 * Integration check: a workflow whose first task throws a *retryable* error (a
 * \Error, per the default ExceptionInterceptor) over the TrueAsync worker —
 * exercises the codec's Panic handling. A Panic must complete the activation as a
 * failed *workflow task* (so the core retries it), NOT as a FailWorkflowExecution
 * (which would terminate the workflow). The proof is behavioural: the workflow
 * throws once, the task is retried, and the second attempt completes normally —
 * so getResult returns the success value instead of throwing. The static attempt
 * counter (shared, single process) confirms the retry actually happened.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_panic.php [address]
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
class TrueAsyncPanicWorkflow
{
    /** Survives task retries (single worker process), so the first attempt panics
     *  and the retry recovers. Test-only non-determinism: it is safe because the
     *  panicking task records no commands, so the retry replays from empty history. */
    public static int $attempts = 0;

    #[WorkflowMethod(name: 'TrueAsyncPanicWorkflow')]
    public function handler()
    {
        self::$attempts++;

        if (self::$attempts === 1) {
            // A \Error is retryable -> the engine issues Panic -> failed task.
            throw new \Error('boom: first workflow task panics on purpose');
        }

        // The recovered attempt waits at a deterministic workflow suspension point.
        Workflow::timer(1);

        return 'recovered';
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

$taskQueue = 'truasync-panic-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-panic-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncPanicWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncPanicWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    // If Panic were mis-encoded as a workflow failure, getResult would throw here.
    $final = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return ['final' => $final, 'attempts' => TrueAsyncPanicWorkflow::$attempts];
}));

if ($result['final'] !== 'recovered' || $result['attempts'] < 2) {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . " (wanted final=recovered, attempts>=2)\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: panic workflow={$wfId} recovered after {$result['attempts']} attempts\n");
exit(0);
