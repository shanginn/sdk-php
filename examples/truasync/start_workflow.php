<?php

/**
 * Reuse the official Temporal client SDK on the TrueAsync + Rust-core transport
 * (no gRPC, no RoadRunner). Requires the `temporal` PHP extension and a running
 * Temporal frontend (e.g. `temporal server start-dev`).
 *
 *   php -d extension=temporal.so examples/truasync/start_workflow.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use TrueAsync\Temporal\Core\Connection;
use function Async\spawn;
use function Async\await;

$execution = await(spawn(static function (): array {
    // Native transport: connects through the Rust core, parks the coroutine on
    // every RPC instead of blocking the thread.
    $core = new Connection('127.0.0.1:7233');

    // The reused WorkflowClient, driven by our ServiceClientInterface adapter.
    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore($core));

    $stub = $client->newUntypedWorkflowStub('DemoWorkflow', (new WorkflowOptions())
        ->withTaskQueue('demo-queue')
        ->withWorkflowId('wf-' . \bin2hex(\random_bytes(4))));

    $run = $client->start($stub, 'hello');

    return [$run->getExecution()->getID(), $run->getExecution()->getRunID()];
}));

printf("started workflow %s (run %s)\n", $execution[0], $execution[1]);
