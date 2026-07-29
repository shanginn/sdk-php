![Temporal PHP SDK](https://raw.githubusercontent.com/temporalio/assets/main/files/w/php.png)

# Temporal PHP SDK for TrueAsync

[![TrueAsync CI](https://github.com/shanginn/sdk-php/actions/workflows/trueasync-ci.yml/badge.svg?branch=true-async)](https://github.com/shanginn/sdk-php/actions/workflows/trueasync-ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

This fork runs Temporal clients, Workflow workers, and Activity workers directly
inside TrueAsync PHP. The Temporal Rust Core bridge performs service RPCs and
task polling; no RoadRunner process, Goridge socket, or PHP gRPC extension is
used.

The public application shape remains familiar:

```php
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\WorkerFactory;

$client = WorkflowClient::create(
    ServiceClient::create('127.0.0.1:7233'),
);

$factory = WorkerFactory::create();
$worker = $factory->newWorker('orders');
$worker->registerWorkflowTypes(OrderWorkflow::class);
$worker->registerActivityImplementations(new OrderActivities());
$factory->run();
```

`WorkerFactory::create()` reads `TEMPORAL_ADDRESS` (default
`127.0.0.1:7233`) and `TEMPORAL_NAMESPACE` (default `default`). A factory may
register multiple task queues; each gets its own Rust Core worker and all of
them run concurrently in one structured TrueAsync scope.

## Requirements

| Component | Requirement |
|---|---|
| PHP | 8.6+ TrueAsync build |
| Async runtime | `ext-true_async` |
| Temporal Core bridge | [`ext-temporal`](https://github.com/shanginn/php-temporal) |
| Protobuf | pure-PHP implementation included; `ext-protobuf` is optional for performance |

The build inputs for TrueAsync PHP are
[`true-async/php-src`](https://github.com/true-async/php-src) and
[`true-async/php-async`](https://github.com/true-async/php-async). The native
Temporal extension is built from
[`shanginn/php-temporal`](https://github.com/shanginn/php-temporal).

Until this fork is published as a stable Composer release, install its branch as
a VCS repository:

```bash
composer config repositories.temporal-sdk vcs https://github.com/shanginn/sdk-php
composer require temporal/sdk:dev-true-async
```

## Client TLS and API keys

The legacy `Temporal\Client\GRPC` namespace is retained for source
compatibility, but calls are routed through Rust Core:

```php
use Temporal\Client\GRPC\ServiceClient;

$service = ServiceClient::createSSL(
    address: 'namespace.tmprl.cloud:7233',
    crt: '/path/to/ca.pem',
    clientKey: '/path/to/client.key',
    clientPem: '/path/to/client.pem',
)->withAuthKey($_ENV['TEMPORAL_API_KEY']);
```

Workers can receive a native connection explicitly:

```php
use Temporal\WorkerFactory;
use TrueAsync\Temporal\Core\Connection;

$factory = WorkerFactory::create(
    connection: new Connection(
        address: 'namespace.tmprl.cloud:7233',
        tls: true,
        apiKey: $_ENV['TEMPORAL_API_KEY'],
    ),
    namespace: 'production',
);
```

## Development and testing

Start a local Temporal service:

```bash
temporal server start-dev --log-level error
```

Then run workers as ordinary PHP programs:

```bash
php worker.php
```

The testing package starts worker commands directly and uses an in-memory
activity-mocking cache. See the [testing guide](testing/README.md).

Offline workflow replay uses the same native Core worker:

```php
use Temporal\Testing\Replay\WorkflowReplayer;

$replayer = new WorkflowReplayer(
    workflowTypes: [OrderWorkflow::class],
);
$replayer->replayFromJSON('OrderWorkflow', 'history.json');
```

Replay completes Core eviction activations before reporting
`NonDeterministicWorkflowException`, so deterministic histories pass and command
mismatches fail without contacting a Temporal server.

## Current native bridge boundaries

Unsupported worker options fail at registration instead of being silently
ignored. The native bridge supports identity, heartbeat throttling, activity
and task-queue rate limits, eager-activity controls, activity-only workers,
legacy Build ID versioning, and Worker Deployment Versioning. It does not yet
expose local-activity rate limiting, RoadRunner sessions, Nexus polling, or a
distinct local-activity-only poll mode. Non-default workflow panic policy and
registration-alias controls are also rejected until Core-equivalent behavior is
wired end to end.

Workflow code must remain deterministic. Use Temporal workflow primitives such
as `Workflow::timer()` and `Workflow::executeActivity()`; use TrueAsync APIs in
clients and Activities, not inside Workflow logic.

## Upstream and attribution

This repository is a TrueAsync-native fork of
[`temporalio/sdk-php`](https://github.com/temporalio/sdk-php). Temporal’s
conceptual and API documentation remains useful:

- [Temporal PHP developer guide](https://docs.temporal.io/develop/php)
- [Workflows](https://docs.temporal.io/workflows)
- [Activities](https://docs.temporal.io/activities)
- [Temporal community forum](https://community.temporal.io/tag/php-sdk)

Fork-specific issues and source live at
[`shanginn/sdk-php`](https://github.com/shanginn/sdk-php).

## License

Temporal PHP SDK is open-source software licensed under the
[MIT license](LICENSE.md).
