<?php

declare(strict_types=1);

/**
 * Integration check: a workflow upserts a search attribute over the TrueAsync
 * worker — exercises the codec's UpsertSearchAttributes command (encode
 * UpsertWorkflowSearchAttributes). Verifies the attribute via DescribeWorkflow.
 * Registers CustomKeywordField first (an OperatorService RPC over the transport,
 * idempotent), so the test is self-contained on a fresh server.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_search_attributes.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesRequest;
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
class TrueAsyncSearchAttrWorkflow
{
    private bool $done = false;

    #[Workflow\SignalMethod(name: 'finish')]
    public function finish(): void
    {
        $this->done = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncSearchAttrWorkflow')]
    public function handler(string $value): iterable
    {
        Workflow::upsertSearchAttributes(['CustomKeywordField' => $value]);

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

$taskQueue = 'truasync-sa-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-sa-' . \bin2hex(\random_bytes(4));
$attrValue = 'tuned-' . \bin2hex(\random_bytes(3));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId, $attrValue): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncSearchAttrWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    // Register the custom attribute so the test is self-contained — dev servers
    // do not pre-register it. AddSearchAttributes is an OperatorService RPC
    // (service 2) and is idempotent; ignore the error when it already exists.
    try {
        $register = (new AddSearchAttributesRequest())
            ->setNamespace('default')
            ->setSearchAttributes(['CustomKeywordField' => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD]);
        (new Connection($address))->rpcCall(2, 'AddSearchAttributes', $register->serializeToString());
        delay(1000);   // let the new mapping propagate before the workflow upserts
    } catch (\Throwable $e) {
        // already registered (or a benign race) — fine
    }

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncSearchAttrWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, $attrValue);

    // Drive to completion first so the upsert is durably applied, then read it
    // back from DescribeWorkflowExecution (no visibility-lag race).
    $stub->signal('finish');
    $final = (string) $run->getResult(null, 30);

    $description = $stub->describe();
    $sa = $description->info->searchAttributes->getValue('CustomKeywordField');

    $worker->shutdown();
    await($loop);

    return ['final' => $final, 'sa' => \is_string($sa) ? $sa : \json_encode($sa)];
}));

if ($result['final'] !== 'done' || $result['sa'] !== $attrValue) {
    \fwrite(\STDERR, 'FAIL: unexpected results: ' . \json_encode($result) . " (wanted sa={$attrValue})\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS: search-attribute workflow={$wfId} CustomKeywordField={$result['sa']}\n");
exit(0);
