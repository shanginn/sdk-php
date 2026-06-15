<?php

declare(strict_types=1);

/**
 * Integration check: a workflow upserts typed search attributes over the
 * TrueAsync worker — exercises the codec's UpsertTypedSearchAttributes command
 * (encode UpsertWorkflowSearchAttributes with per-payload `type` metadata). It
 * sets an Int, a Keyword and a Bool, then unsets the Bool on a second task, and
 * verifies via DescribeWorkflowExecution that the typed values round-trip and the
 * unset attribute is gone. Registers the custom attributes first (an
 * OperatorService RPC over the transport, idempotent) so it is self-contained.
 * Requires the `temporal` extension and a running Temporal frontend.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_typed_search_attributes.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesRequest;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\SearchAttributes\SearchAttributeUpdate;
use Temporal\Common\SearchAttributes\ValueType;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;
use function Async\await;
use function Async\delay;
use function Async\spawn;

#[Workflow\WorkflowInterface]
class TrueAsyncTypedSAWorkflow
{
    private bool $unset = false;
    private bool $done = false;

    #[Workflow\SignalMethod(name: 'unset')]
    public function unset(): void
    {
        $this->unset = true;
    }

    #[Workflow\SignalMethod(name: 'finish')]
    public function finish(): void
    {
        $this->done = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncTypedSAWorkflow')]
    public function handler(int $number, string $word): iterable
    {
        Workflow::upsertTypedSearchAttributes(
            SearchAttributeUpdate::valueSet('CustomIntField', ValueType::Int, $number),
            SearchAttributeUpdate::valueSet('CustomKeywordField', ValueType::Keyword, $word),
            SearchAttributeUpdate::valueSet('CustomBoolField', ValueType::Bool, true),
        );

        yield Workflow::await(fn() => $this->unset);

        Workflow::upsertTypedSearchAttributes(
            SearchAttributeUpdate::valueUnset('CustomBoolField', ValueType::Bool),
        );

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

$taskQueue = 'truasync-tsa-' . \bin2hex(\random_bytes(3));
$wfId = 'truasync-tsa-' . \bin2hex(\random_bytes(4));
$number = \random_int(1, 1_000_000);
$word = 'kw-' . \bin2hex(\random_bytes(3));

$result = await(spawn(static function () use ($address, $taskQueue, $wfId, $number, $word): array {
    $core = new CoreWorker(new Connection($address), $taskQueue, 'default', 10);
    $worker = new TemporalWorker($core, $taskQueue);
    $worker->registerWorkflowTypes(TrueAsyncTypedSAWorkflow::class);

    $loop = spawn(fn() => $worker->run());

    // Register the custom attributes so the test is self-contained — dev servers
    // do not always pre-register them. AddSearchAttributes is an OperatorService
    // RPC (service 2) and is idempotent; ignore the error when they already exist.
    try {
        $register = (new AddSearchAttributesRequest())
            ->setNamespace('default')
            ->setSearchAttributes([
                'CustomIntField' => IndexedValueType::INDEXED_VALUE_TYPE_INT,
                'CustomKeywordField' => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD,
                'CustomBoolField' => IndexedValueType::INDEXED_VALUE_TYPE_BOOL,
            ]);
        (new Connection($address))->rpcCall(2, 'AddSearchAttributes', $register->serializeToString());
        delay(1000);   // let the new mappings propagate before the workflow upserts
    } catch (\Throwable $e) {
        // already registered (or a benign race) — fine
    }

    $client = WorkflowClient::create(TrueAsyncServiceClient::fromCore(new Connection($address)));
    $stub = $client->newUntypedWorkflowStub('TrueAsyncTypedSAWorkflow', (new WorkflowOptions())
        ->withTaskQueue($taskQueue)
        ->withWorkflowId($wfId));
    $run = $client->start($stub, $number, $word);

    // Drive set -> unset -> finish, so both upserts are durably applied before we
    // read mutable state back via describe (no visibility-lag race).
    $stub->signal('unset');
    $stub->signal('finish');
    $final = (string) $run->getResult(null, 30);

    $sa = $stub->describe()->info->searchAttributes;

    $worker->shutdown();
    await($loop);

    return [
        'final' => $final,
        'int' => $sa->getValue('CustomIntField'),
        'keyword' => $sa->getValue('CustomKeywordField'),
        'boolPresent' => \array_key_exists('CustomBoolField', $sa->getValues()),
    ];
}));

if (
    $result['final'] !== 'done'
    || $result['int'] !== $number
    || $result['keyword'] !== $word
    || $result['boolPresent'] !== false
) {
    \fwrite(
        \STDERR,
        'FAIL: unexpected results: ' . \json_encode($result)
        . " (wanted int={$number}, keyword={$word}, boolPresent=false)\n",
    );
    exit(1);
}

\fwrite(
    \STDOUT,
    "PASS: typed-search-attribute workflow={$wfId} int={$result['int']} keyword={$result['keyword']} (bool unset)\n",
);
exit(0);
