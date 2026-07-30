# Standalone Activities

Standalone Activities run on an ordinary Activity worker but are scheduled
directly by a client instead of by a Workflow. This is a Public Preview API and
requires Temporal Server 1.31 or newer with the Standalone Activities feature
enabled.

The feature is appropriate when an operation needs Temporal retries,
heartbeats, cancellation, visibility, and durable result retrieval, but does
not need Workflow orchestration or Workflow history.

## Start and wait for a result

```php
use Temporal\Client\Activity\ActivityOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Common\RetryOptions;

$workflowClient = WorkflowClient::create(
    ServiceClient::create('127.0.0.1:7233'),
);
$activities = $workflowClient->newActivityClient();

$options = ActivityOptions::new(
    activityId: 'resize-image-42',
    taskQueue: 'media',
)
    ->withScheduleToCloseTimeout('5 minutes')
    ->withStartToCloseTimeout('1 minute')
    ->withHeartbeatTimeout('10 seconds')
    ->withRetryOptions(
        RetryOptions::new()->withMaximumAttempts(5),
    )
    ->withSummary('Resize customer image')
    ->withDetails('Source object: uploads/42.jpg');

$handle = $activities->start(
    'ImageActivities.resize',
    $options,
    'uploads/42.jpg',
    1280,
    720,
);

$result = $handle->getResult('string');
```

Pass the expected result type to `getResult()` when the default converter
cannot infer it. Result retrieval transparently repeats Temporal's bounded
long poll until the Activity reaches a terminal state.

`ActivityClient::execute()` is the start-and-wait shorthand:

```php
$result = $activities->execute(
    'ImageActivities.resize',
    $options,
    'uploads/42.jpg',
    1280,
    720,
);
```

Activity ID, task queue, and either schedule-to-close or start-to-close timeout
are required. The client validates these before sending the start request.

## Reattach and manage lifecycle

Handles are durable references. Persist the Activity ID and run ID, then
reattach after a process restart:

```php
$handle = $activities->getHandle(
    activityId: 'resize-image-42',
    runId: $storedRunId,
);

$description = $handle->describe();

$handle->cancel('Customer removed the source image');
$handle->terminate('Administrative stop');
$handle->delete();
```

Omit the run ID to target the latest execution for that Activity ID:

```php
$latest = $activities->getHandle('resize-image-42');
```

Cancellation is cooperative. A running Activity should heartbeat and handle
`ActivityCanceledException`. Termination is immediate from the service's point
of view.

## Asynchronous completion

An Activity may return control to the worker and be completed by another
process:

```php
use Temporal\Activity;

// Inside the Activity implementation:
$info = Activity::getInfo();
Activity::doNotCompleteOnReturn();

// Persist these values for the completing process:
$activityId = $info->id;
$activityRunId = $info->activityRunId;
```

Complete it through the existing Activity completion client. A standalone
Activity is addressed by an empty Workflow ID, its Activity run ID, and its
Activity ID. Bind the same Activity serialization context before completing so
context-aware payload converters can encode the result consistently:

```php
use Temporal\DataConverter\ActivitySerializationContext;

$completion = $workflowClient
    ->newActivityCompletionClient()
    ->withContext(new ActivitySerializationContext(
        namespace: 'default',
        activityType: 'ImageActivities.resize',
        taskQueue: 'media',
        workflowId: null,
        workflowType: null,
        isLocal: false,
    ));
$completion->complete(
    workflowId: '',
    runId: $activityRunId,
    activityId: $activityId,
    result: 'finished asynchronously',
);
```

The same addressing applies to `completeExceptionally()`,
`reportCancellation()`, and `recordHeartbeat()`. Use `withContext()` for those
operations as well whenever the converter depends on Activity ownership.

## Headers, search attributes, priority, and delayed start

```php
use Temporal\Common\ActivityIdConflictPolicy;
use Temporal\Common\ActivityIdReusePolicy;
use Temporal\Common\Priority;

$options = ActivityOptions::new('invoice-2026-0042', 'billing')
    ->withScheduleToCloseTimeout('10 minutes')
    ->withHeaders([
        'trace-id' => 'f01b',
        'tenant-id' => 'acme',
    ])
    ->withSearchAttributes([
        'CustomerId' => 'acme',
    ])
    ->withPriority(
        Priority::new(1)->withFairnessKey('acme'),
    )
    ->withStartDelay('30 seconds')
    ->withIdReusePolicy(ActivityIdReusePolicy::RejectDuplicate)
    ->withIdConflictPolicy(ActivityIdConflictPolicy::UseExisting);
```

Use `withTypedSearchAttributes()` instead of `withSearchAttributes()` when the
Search Attribute key types are known. The two forms cannot be mixed on one
start request.

## List and count

Standalone Activities use Temporal Visibility queries:

```php
$page = $activities->list(
    query: 'ActivityType = "ImageActivities.resize"',
    pageSize: 100,
);

foreach ($page as $execution) {
    printf(
        "%s %s %d\n",
        $execution->activityId,
        $execution->runId,
        $execution->status,
    );
}

$count = $activities->count('ExecutionStatus = "Running"');
echo $count->count;
```

`list()` returns a paginator. Iterating it transparently fetches subsequent
pages; `count($page)` performs a separate count query.

## Activity worker context

The same registered implementation can serve Workflow-scheduled and standalone
Activity tasks:

```php
use Temporal\Activity;

$info = Activity::getInfo();

if ($info->isInWorkflow()) {
    $workflowId = $info->workflowExecution?->getID();
} else {
    $standaloneRunId = $info->activityRunId;
}

$namespace = $info->namespace;
```

For standalone executions, `workflowExecution` and `workflowType` are `null`;
`namespace` remains populated and `activityRunId` contains the standalone run
ID. `workflowNamespace` remains available as a deprecated compatibility alias
of `namespace`. Existing Workflow-scheduled Activity behavior is unchanged.
