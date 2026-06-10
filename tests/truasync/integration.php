<?php

declare(strict_types=1);

/**
 * Integration check: the reused WorkflowClient over the TrueAsync transport.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/integration.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Api\Workflowservice\V1\GetSystemInfoRequest;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use TrueAsync\Temporal\Core\Connection;
use function Async\await;
use function Async\spawn;

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

[$version, $workflowId, $runId] = await(spawn(static function () use ($address): array {
    $svc = TrueAsyncServiceClient::fromCore(new Connection($address));

    // 1. A raw RPC round-trips to a typed response.
    $info = $svc->GetSystemInfo(new GetSystemInfoRequest());

    // 2. The full WorkflowClient starts a workflow over the transport.
    $client = WorkflowClient::create($svc);
    $stub = $client->newUntypedWorkflowStub('IntegrationWorkflow', (new WorkflowOptions())
        ->withTaskQueue('integration-q')
        ->withWorkflowId('it-' . \bin2hex(\random_bytes(4))));
    $run = $client->start($stub, 'payload');

    return [$info->getServerVersion(), $run->getExecution()->getID(), $run->getExecution()->getRunID()];
}));

if ($version === '' || $workflowId === '' || !$runId) {
    \fwrite(\STDERR, "FAIL: unexpected empty result\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: server={$version} workflow={$workflowId} run={$runId}\n");
exit(0);
