<?php

declare(strict_types=1);

/**
 * Integration check: Workflow::getVersion() over the TrueAsync worker — exercises
 * the codec's GetVersion handling (encode SetPatchMarker, decode notify_has_patch,
 * resolve the version locally). The hard part is replay: the worker runs with
 * maxCachedWorkflows=0, so the core evicts the run after every workflow task and
 * REPLAYS it from history on the next one — delivering notify_has_patch up front.
 * The workflow calls getVersion before and after a timer (a task boundary), so the
 * second call happens on a replayed task and MUST observe the same version; if it
 * did not, the workflow would take a different branch and the core would fail the
 * task for non-determinism. A clean completion proves replay stability.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_versioning.php [address]
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
class TrueAsyncVersioningWorkflow
{
    #[WorkflowMethod(name: 'TrueAsyncVersioningWorkflow')]
    public function handler()
    {
        $before = Workflow::getVersion('a-change', Workflow::DEFAULT_VERSION, 1);

        // A timer ends this workflow task; with caching off the next task replays
        // from history (the core sends notify_has_patch for 'a-change' first).
        Workflow::timer(1);

        $after = Workflow::getVersion('a-change', Workflow::DEFAULT_VERSION, 1);

        if ($before !== $after) {
            throw new \RuntimeException("version changed across replay: {$before} -> {$after}");
        }

        return "version={$before}";
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

$taskQueue = 'truasync-ver-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-ver-' . \bin2hex(\random_bytes(4));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId): string {
    // maxCachedWorkflows=0 forces a replay on every workflow task.
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10, ['maxCachedWorkflows' => 0]);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncVersioningWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncVersioningWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub);

    $value = (string) $run->getResult(null, 30);

    $worker->shutdown();
    await($loop);

    return $value;
}));

// First encounter records the patch, so getVersion returns maxSupported (1).
if ($result !== 'version=1') {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result} (wanted version=1)\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: versioning workflow={$wfId} result={$result}\n");
exit(0);
