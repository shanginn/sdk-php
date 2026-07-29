# Testing with the TrueAsync worker

The testing package launches the Temporal test server and a normal PHP worker
process. The worker uses `Temporal\Testing\WorkerFactory`, which keeps the
production native runtime while adding process-safe Activity mocking.

## Bootstrap

Download the test binaries once:

```bash
composer get:binaries
```

Create a worker entry point:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Temporal\Testing\WorkerFactory;

$factory = WorkerFactory::create();
$worker = $factory->newWorker('tests');
$worker->registerWorkflowTypes(MyWorkflow::class);
$worker->registerActivityImplementations(new MyActivities());
$factory->run();
```

Start the test server and worker from the PHPUnit bootstrap:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Temporal\Testing\Environment;

$environment = Environment::create();
$environment->startTemporalTestServer();
$environment->startWorker(
    [PHP_BINARY, __DIR__ . '/worker.php'],
    envs: [
        'TEMPORAL_ADDRESS' => (string) $environment->command->address,
        'TEMPORAL_NAMESPACE' => (string) $environment->command->namespace,
    ],
);

register_shutdown_function(static fn() => $environment->stop());
```

`startWorker()` accepts an argument vector, so there is no shell interpolation:

```php
$environment->startWorker([
    PHP_BINARY,
    '-d',
    'memory_limit=-1',
    __DIR__ . '/worker.php',
]);
```

Create multiple `Environment` instances when tests require separate worker
processes. A single `WorkerFactory` can also register multiple task queues.

## Time skipping

The test server starts with time skipping enabled. The native test-service
client uses Rust Core’s test-service RPC selector:

```php
use Temporal\Testing\TestService;

$testService = TestService::create('127.0.0.1:7233');
$testService->lockTimeSkipping();
$testService->sleep(10);
$testService->unlockTimeSkipping();
```

`sleepUntil()` advances to a timestamp and `getCurrentTime()` returns the
server’s current time. Tests that need real timer progression can use the
`WithoutTimeSkipping` trait.

## Mocking Activities

The testing factory decorates registered workers with a locked file-backed
invocation cache. No external key-value service is required.

```php
use Temporal\Testing\ActivityMocker;

$mocks = new ActivityMocker();
$mocks->expectCompletion('SimpleActivity.doSomething', 'world');

try {
    $workflow = $workflowClient->newWorkflowStub(SimpleWorkflow::class);
    $run = $workflowClient->start($workflow, 'hello');
    self::assertSame('world', $run->getResult('string'));
} finally {
    $mocks->clear();
}
```

To return an Activity failure:

```php
$mocks->expectFailure(
    'SimpleActivity.doSomething',
    new LogicException('something went wrong'),
);
```

Set `TEMPORAL_TEST_CACHE_FILE` to choose the shared cache location. By default it
uses `runtime/temporal-test-cache.bin` under the project working directory.

## Offline replay

Register the workflow classes the replayer may load, then replay protobuf JSON:

```php
use Temporal\Testing\Replay\WorkflowReplayer;

$replayer = new WorkflowReplayer(
    workflowTypes: [MyWorkflow::class],
);
$replayer->replayFromJSON('MyWorkflow', __DIR__ . '/history.json');
```

The replay worker is fully offline. It returns normally for a matching history
and throws `NonDeterministicWorkflowException` when the workflow emits commands
that do not match history.
