# Worker tuning

TrueAsync Workers can delegate concurrency and polling decisions to Temporal
Core. The public tuning objects are immutable and configure all four task
types: Workflow, Activity, Local Activity, and Nexus.

## Resource-based slots

```php
use Temporal\Worker\Tuning\ResourceBasedSlotConfig;
use Temporal\Worker\Tuning\ResourceBasedTuner;
use Temporal\Worker\Tuning\ResourceBasedTunerConfig;
use Temporal\Worker\WorkerOptions;

$options = WorkerOptions::new()->withTuner(new ResourceBasedTuner(
    new ResourceBasedTunerConfig(
        targetMemoryUsage: 0.75,
        targetCpuUsage: 0.85,
    ),
    workflowConfig: new ResourceBasedSlotConfig(
        minimumSlots: 5,
        maximumSlots: 250,
        rampThrottleMs: 0,
    ),
    activityConfig: new ResourceBasedSlotConfig(
        minimumSlots: 1,
        maximumSlots: 500,
        rampThrottleMs: 50,
    ),
));
```

Unset per-task values use the same defaults as the Core-based Python SDK:
Workflow slots use a minimum of 5 and no ramp delay; Activity, Local Activity,
and Nexus slots use a minimum of 1 and a 50 ms ramp delay; all four use a
maximum of 500.

Use `CompositeTuner` to mix fixed-size and resource-based suppliers:

```php
use Temporal\Worker\Tuning\CompositeTuner;
use Temporal\Worker\Tuning\FixedSizeSlotSupplier;
use Temporal\Worker\Tuning\ResourceBasedSlotConfig;
use Temporal\Worker\Tuning\ResourceBasedSlotSupplier;
use Temporal\Worker\Tuning\ResourceBasedTunerConfig;

$resources = new ResourceBasedTunerConfig(0.75, 0.85);

$tuner = new CompositeTuner(
    workflowSlotSupplier: new FixedSizeSlotSupplier(100),
    activitySlotSupplier: new ResourceBasedSlotSupplier(
        new ResourceBasedSlotConfig(maximumSlots: 500),
        $resources,
    ),
    localActivitySlotSupplier: new FixedSizeSlotSupplier(100),
    nexusSlotSupplier: new FixedSizeSlotSupplier(100),
);
```

All resource-based suppliers in one composite must share the same resource
targets. Custom callback suppliers are not supported yet.

`withTuner()` is mutually exclusive with the legacy
`withMaxConcurrent*ExecutionSize()` methods.

## Poller autoscaling

```php
use Temporal\Worker\Tuning\PollerBehaviorAutoscaling;
use Temporal\Worker\Tuning\PollerBehaviorSimpleMaximum;

$options = $options
    ->withWorkflowTaskPollerBehavior(new PollerBehaviorAutoscaling(
        minimum: 2,
        maximum: 100,
        initial: 5,
    ))
    ->withActivityTaskPollerBehavior(new PollerBehaviorAutoscaling())
    ->withNexusTaskPollerBehavior(new PollerBehaviorSimpleMaximum(5));
```

Autoscaling starts at `initial`, never drops below `minimum`, and never exceeds
`maximum`. Core adjusts the number of open long polls using feedback from the
Temporal Server, and it only starts a poll when a task slot is available.

Each new poller behavior is mutually exclusive with its legacy
`withMaxConcurrent*TaskPollers()` method. The SDK still sends the selected
maximum as the legacy fixed fallback so older native bridges degrade to a
bounded simple-maximum strategy.
