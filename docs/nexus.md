# Nexus

Nexus connects a caller Workflow to a service implemented by a Worker, including
across Temporal Namespaces. The PHP SDK supports calls made from Workflows,
synchronous handlers, and asynchronous operations backed by Temporal Workflow
runs.

This guide describes the supported production path. The lower-level
`OperationHandlerInterface` API is available for specialized integrations, but
custom operation-token lifecycles are not part of the production support
boundary documented here.

## Requirements

Use the following components together:

| Component | Requirement |
|---|---|
| Temporal Server | 1.31 or newer |
| Temporal CLI | A version containing Temporal Server 1.31 or newer when using `server start-dev` |
| PHP SDK | A build containing this Nexus implementation |
| RoadRunner | A build whose Temporal plugin contains the matching Nexus bridge |
| PHP | The PHP versions supported by this SDK |

The standard RoadRunner 2025.1.15 distribution does not contain the required
Nexus polling bridge. A Worker may start successfully with that binary while
Nexus requests remain unhandled, so a successful process start is not a
compatibility check. Use the bridge-enabled RoadRunner build pinned by the
release or deployment, and run the Nexus acceptance suite before upgrading
either side.

The wire implementation targets
[`nexus-rpc/api@494165f`](https://github.com/nexus-rpc/api/blob/494165f890be9418c67dfce9c138694fe5c27855/SPEC.md).
That exact revision defines the timeout, operation-token, header, link, and
failure grammar enforced by this release.

This branch is validated against
[`shanginn/roadrunner-temporal@a388917dabd31f30e46a4e4f25da514fd79a9ec8`](https://github.com/shanginn/roadrunner-temporal/commit/a388917dabd31f30e46a4e4f25da514fd79a9ec8),
built into RoadRunner `v2025.1.15` at immutable host commit
`321b817fab1056e404533ca1ddd200e77d1525fd` by that repository's
`tests/acceptance/build-local.sh`. The full commit is an intentional
compatibility pin; do not replace it with a moving branch in production or CI.

Nexus became generally available with Temporal Server 1.27. Server 1.31 is
required here because the PHP implementation and its acceptance suite cover the
current operation timeout and failure behavior, including Schedule-to-Start and
Start-to-Close timeouts.

The checked-in downloader pins Temporal CLI 1.8.1, whose development server is
Temporal Server 1.31.2, for local and CI acceptance runs.

## Architecture

A Nexus Endpoint is a cluster-level routing resource. It maps an Endpoint name
to a target Namespace and Task Queue:

```text
caller Workflow
    -> Nexus Endpoint
        -> handler Namespace / Task Queue
            -> PHP Nexus service
                -> synchronous result
                or backing Workflow run
```

The caller and handler can use different Namespaces. The Endpoint, service, and
operation names are durable wire contracts; changing a PHP class or method name
must not silently change those names in production.

## Define a service contract

Put `#[Service]` on an interface and `#[Operation]` on every operation. Give
both explicit wire names.

Nexus operations accept zero or one input value. Use a DTO when an operation has
multiple logical inputs.

```php
<?php

namespace App\Payments;

use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;

#[Service(name: 'payments.v1')]
interface PaymentsService
{
    #[Operation(name: 'capture')]
    public function capture(CaptureInput $input): CaptureResult;
}
```

For a synchronous operation, implement the contract with an ordinary method:

```php
<?php

namespace App\Payments;

final class PaymentsServiceImpl implements PaymentsService
{
    public function capture(CaptureInput $input): CaptureResult
    {
        return $this->gateway->capture($input);
    }
}
```

Synchronous handlers must finish within the Nexus synchronous request deadline.
Use them only for bounded work. Use a Workflow-backed operation for durable,
long-running, retryable, or cancellable work.

## Implement a Workflow-backed operation

A Workflow-backed operation returns a `WorkflowHandle`. The `output` argument
declares the operation's wire result because the PHP return type is the handle,
not the eventual result.

```php
<?php

namespace App\Fulfillment;

use Temporal\Client\WorkflowOptions;
use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\WorkflowHandle;

#[Service(name: 'fulfillment.v1')]
final class FulfillmentService
{
    #[Operation(name: 'ship', output: ShipmentResult::class)]
    public function ship(ShipmentInput $input): WorkflowHandle
    {
        $requestId = Nexus::getStartDetails()->requestId;

        return WorkflowHandle::fromWorkflowMethod(
            ShipmentWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId($requestId),
            $input,
        );
    }
}
```

The Nexus request ID is stable across delivery retries. Using it as the backing
Workflow ID makes the start idempotent. Configure a Workflow ID reuse policy
that rejects duplicates when the business contract requires one backing
execution per Nexus request.

The SDK generates an opaque operation token that identifies the backing
Workflow. Do not parse, expose, persist as business data, or log this token.

## Register handler Workers

Create the `WorkerFactory` with a Workflow client when any registered Nexus
operation starts a backing Workflow:

```php
<?php

use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\ClientOptions;
use Temporal\Client\WorkflowClient;
use Temporal\WorkerFactory;

$service = ServiceClient::create('temporal:7233');
$workflowClient = WorkflowClient::create(
    $service,
    (new ClientOptions())->withNamespace('fulfillment'),
);

$factory = WorkerFactory::create(client: $workflowClient);
$worker = $factory->newWorker('fulfillment-handler');

$worker->registerWorkflowTypes(ShipmentWorkflowImpl::class);
$worker->registerNexusServiceImplementation(new FulfillmentService());

$factory->run();
```

Register the service and its backing Workflows on the target Task Queue. Nexus,
Workflow, and Activity processing may coexist on the same PHP Worker.
For Workflow-backed operations, the `WorkflowClient` Namespace must equal the
active Nexus operation Namespace (the Endpoint target Namespace). The SDK checks
this before starting the backing Workflow so that operation tokens and Workflow
event links cannot identify a different Namespace from the actual execution.

## Create the Endpoint

Create Namespaces before the Endpoint, then route the Endpoint to the handler
Namespace and Task Queue:

```bash
temporal operator nexus endpoint create \
  --name fulfillment-production \
  --target-namespace fulfillment \
  --target-task-queue fulfillment-handler
```

Treat Endpoint creation, update, and deletion as deployment operations. Check
the current Endpoint version immediately before an update or delete, and remove
ephemeral test Endpoints in a `finally`/cleanup phase.

Use distinct Endpoint and Task Queue names for each environment. Do not route a
production caller through a development Endpoint.

## Call a service from a Workflow

Create the typed stub inside Workflow code. Always set an Endpoint and an
overall Schedule-to-Close timeout.

```php
<?php

use Carbon\CarbonInterval;
use Temporal\Workflow;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\NexusOperationOptions;

$payments = Workflow::newNexusServiceStub(
    PaymentsService::class,
    NexusOperationOptions::new()
        ->withEndpoint('payments-production')
        ->withSummary('Capture the payment')
        ->withScheduleToCloseTimeout(CarbonInterval::minutes(2))
        ->withScheduleToStartTimeout(CarbonInterval::seconds(20))
        ->withStartToCloseTimeout(CarbonInterval::seconds(90))
        ->withCancellationType(NexusOperationCancellationType::WaitCompleted),
);

$result = yield $payments->capture($input);
```

Use `Workflow::newUntypedNexusOperationStub()` only when the wire service and
operation names cannot be known through a PHP contract. A typed contract catches
name and payload-type mistakes earlier.

Calling an operation is a deterministic Workflow command. Keep Endpoint,
service, operation, timeout, cancellation, and header decisions deterministic;
do not read environment variables or perform I/O from Workflow code.

`withSummary()` sets a single-line fixed summary that appears in Temporal UI/CLI
and is recorded as UserMetadata on the Nexus operation's scheduled event. The
summary may use single-line Temporal Markdown. Passing an empty string clears
it. Keep the value concise, deterministic, and free of secrets.

## Timeouts

Nexus timeouts are part of the caller's durable command:

- **Schedule-to-Close** is the total operation budget. Set it for every call.
- **Schedule-to-Start** limits how long the operation may wait for a handler.
- **Start-to-Close** limits an asynchronous operation after it starts.
- The handler's request deadline is separate and bounds an individual start or
  cancel request.

Zero means unspecified at the SDK layer. Negative and invalid durations are
rejected before a command is emitted. Choose values from service-level
objectives and include queueing time in Schedule-to-Close.

## Cancellation

Select cancellation behavior deliberately:

| Type | Caller behavior |
|---|---|
| `Abandon` | Stops waiting and does not request operation cancellation |
| `TryCancel` | Requests cancellation and stops waiting |
| `WaitRequested` | Waits until the cancellation request is accepted |
| `WaitCompleted` | Waits until the operation reaches a terminal state |
| `Unspecified` | Uses the server/SDK default |

Use `WaitCompleted` when the caller must know cleanup has finished. Use
`Abandon` only when the backing operation is intentionally independent of the
caller.

For Workflow-backed operations, cancellation targets the backing Workflow. Make
that Workflow cancellation-safe: propagate cancellation to child work as
appropriate and keep compensating cleanup bounded.

Handler-method cancellation is separate from cancellation of the Nexus
operation. It tells a currently executing `start()` or `cancel()` request to
stop work, for example because its request context was canceled. Long-running
handler methods must cooperate by polling:

```php
while (!$job->isFinished()) {
    if ($context->isMethodCancelled()) {
        $job->stop($context->getMethodCancellationReason());
        throw \Temporal\Nexus\Exception\HandlerException::create(
            \Temporal\Nexus\Exception\ErrorType::RequestTimeout,
            'Nexus handler request was cancelled.',
        );
    }

    $job->advance();
}
```

While no cancellation has been observed, `isMethodCancelled()`,
`getMethodCancellationReason()`, and listener registration each perform a
synchronous cancellation poll through RoadRunner. Once observed, the result and
reason are cached for that handler invocation. A request deadline is checked
locally before the remote poll.

Listener registration performs one immediate poll; it does not create a
background watcher. A registered listener runs when a later inspection observes
cancellation (or when a compatible same-process transport delivers it
directly). Code that blocks without periodically inspecting cancellation cannot
be interrupted cooperatively. Keep the polling interval bounded but avoid
polling in a tight loop.

## Failures and retries

Use the exception type that matches the failure domain:

- Throw `OperationException::failed()` for an expected terminal business
  failure.
- Throw `OperationException::canceled()` for a terminal canceled result.
- Throw `HandlerException` for request, authorization, routing, capacity, or
  handler-infrastructure errors.
- Let unexpected exceptions reach the SDK. They are logged on the handler side
  and returned as a generic internal handler error.

Treat an explicit `OperationException`, and a `HandlerException` other than an
internal or unavailable error, as caller-visible in its entirety. Its message,
details, stack trace, and serialized cause chain can be carried in the Nexus
failure. Never attach a secret-bearing `Throwable`, even when the top-level
message is safe. Do not include credentials, connection strings, SQL, internal
hostnames, payload contents, or operation tokens anywhere in these exceptions.
Internal and unavailable handler errors are redacted by the bridge, but handler
code should still avoid putting secrets in them.

Nexus start delivery is at least once. Handler implementations must be
idempotent even when the caller invokes an operation only once. The stable
request ID is the primary deduplication key for Workflow-backed operations.

## Headers, payloads, and security

Nexus headers are raw strings. Read handler-side values through the current
operation context:

```php
$authorization = Nexus::getCurrentOperationContext()
    ->headers
    ->get('authorization');
```

Header names are case-insensitive. Authenticate and authorize each operation at
the handler boundary, preferably in an inbound Nexus interceptor. Header names
and values must both be strings; the SDK rejects malformed arrays before it
schedules the operation.

Do not put secrets in Nexus headers. Caller-provided Nexus headers can be
recorded in Workflow history and are not protected by a payload codec. Pass
short-lived references or non-secret claims instead.

Caller and handler Workers must use compatible Data Converters and payload
codecs. A custom payload codec protects payloads, not raw Nexus headers.
Deploy converter changes with a backward-compatible rollout because existing
histories and in-flight operations retain older payloads.

## Links and observability

Links associate a Nexus operation with Temporal Workflow event references.
Validate untrusted links before using them, and preserve only supported link
types.

Record service, operation, Endpoint, target Namespace, Task Queue, result class,
latency, and retry/cancellation counts. Do not record payload bodies, raw
authorization headers, callback headers, or operation tokens.

Alert on:

- Endpoint routing failures;
- no Nexus pollers on the target Task Queue;
- handler request deadline exhaustion;
- Schedule-to-Start and Schedule-to-Close timeouts;
- retryable handler failures and resource exhaustion;
- incompatible PHP SDK/RoadRunner bridge deployments.

## Testing

Unit tests can mock a Nexus service implementation. Production validation must
also use a real Temporal Server and the matching RoadRunner bridge:

```bash
sdk_dir="$(pwd)"
git clone --no-checkout \
  https://github.com/shanginn/roadrunner-temporal.git \
  "${sdk_dir}/../roadrunner-temporal-nexus"
git -C "${sdk_dir}/../roadrunner-temporal-nexus" checkout --detach \
  a388917dabd31f30e46a4e4f25da514fd79a9ec8
ROADRUNNER_BINARY="${sdk_dir}/rr-nexus" \
  "${sdk_dir}/../roadrunner-temporal-nexus/tests/acceptance/build-local.sh"

composer test:unit
ROADRUNNER_BINARY="${sdk_dir}/rr-nexus" composer test:nexus
```

Keep this bridge-enabled `rr-nexus` binary separate from the stock RoadRunner
binary used by the SDK's non-Nexus suites. Setting `ROADRUNNER_BINARY` only for
`composer test:nexus` prevents the compatibility pin from silently changing
those suites.

The Nexus acceptance suite covers:

- Worker registration and service routing;
- typed inputs and synchronous results;
- Workflow-backed asynchronous completion;
- operation and handler failures;
- all cancellation modes and cancellation races;
- headers, links, reverse links, and interceptors;
- request-ID idempotency;
- Schedule-to-Close, Schedule-to-Start, and Start-to-Close behavior;
- parallel callers and mixed sync/async operations;
- Workflow replay;
- Nexus, Workflow, and Activity coexistence.

Run the examples' cross-Namespace E2E command as a release gate as well. A green
unit suite or a Worker process that merely stays running does not prove that the
RoadRunner binary polls Nexus tasks.

## Deployment checklist

1. Pin the PHP SDK and RoadRunner Nexus bridge to tested commits.
2. Run unit, functional, replay, and Nexus acceptance suites.
3. Verify the Temporal Server is 1.31 or newer.
4. Create or verify the target Namespace and Task Queue.
5. Deploy handler Workers and confirm Nexus pollers are active.
6. Create or update the Endpoint using its current version.
7. Run a cross-Namespace synchronous and asynchronous smoke test.
8. Verify failure redaction, cancellation cleanup, and timeout alerts.
9. Keep the previous compatible Worker build available for rollback.
10. Delete temporary Endpoints and Namespaces created by validation.

Upgrade the PHP SDK and RoadRunner bridge together unless their release notes
explicitly guarantee wire compatibility. Re-run the complete Nexus suite after
every Temporal Server, SDK, RoadRunner, Data Converter, or payload-codec change.

## Current support boundary

Supported and tested:

- Nexus calls issued from PHP Workflows;
- synchronous PHP operation methods;
- Workflow-backed asynchronous operations;
- typed and untyped caller stubs;
- timeouts, cancellation, failures, headers, links, interceptors, and replay;
- cross-Namespace routing through a Temporal Nexus Endpoint.

Not included in the production support boundary:

- starting Nexus operations from the standalone PHP client;
- arbitrary non-Temporal asynchronous backends with custom operation-token
  storage and polling;
- relying on raw HTTP access to a Temporal Nexus Endpoint as an application API.

For Nexus concepts and cluster behavior, see the
[Temporal Nexus documentation](https://docs.temporal.io/nexus/).
