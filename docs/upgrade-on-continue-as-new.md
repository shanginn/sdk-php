# Upgrade on Continue-As-New

Pinned Workflows normally keep using the same Worker Deployment Version for
their whole run chain. Upgrade-on-Continue-As-New lets a Workflow make an
explicit, history-recorded decision to start the next run on the Task Queue's
Target or Ramping Version.

The feature is experimental. Worker Deployment Versioning must be enabled for
the Worker and supported by the Temporal Service.

## Upgrade a Pinned Workflow

Check the current Workflow Task metadata, then set the behavior on the
Continue-As-New command:

```php
use Temporal\Workflow;
use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ContinueAsNewVersioningBehavior;

$info = Workflow::getInfo();

if ($info->targetWorkerDeploymentVersionChanged) {
    return Workflow::continueAsNew(
        type: 'BillingWorkflow',
        args: [$generation + 1],
        options: ContinueAsNewOptions::new()
            ->withInitialVersioningBehavior(
                ContinueAsNewVersioningBehavior::AutoUpgrade,
            ),
    );
}
```

`AutoUpgrade` sends the first Workflow Task of the new run to the Task Queue's
Target Version. `UseRampingVersion` applies the Task Queue's ramp selection for
the Workflow ID and falls back to its Current Version when no ramp is active.
After the first task, the Workflow uses the `#[WorkflowVersioningBehavior]`
declared by its code.

`Unspecified` is the default and preserves the previous run's behavior. A
Pinned Versioning Override takes precedence over every Continue-As-New setting.

The selected behavior is part of the durable Continue-As-New command. Do not
choose it from environment variables, wall-clock time, or another
non-deterministic source.

## Inspect Continue-As-New suggestions

The Service can suggest Continue-As-New as history or Workflow Update limits
approach:

```php
use Temporal\Workflow;
use Temporal\Workflow\ContinueAsNewSuggestedReason;

$info = Workflow::getInfo();
if ($info->shouldContinueAsNew) {
    foreach ($info->continueAsNewSuggestedReasons as $reason) {
        match ($reason) {
            ContinueAsNewSuggestedReason::HistorySizeTooLarge => null,
            ContinueAsNewSuggestedReason::TooManyHistoryEvents => null,
            ContinueAsNewSuggestedReason::TooManyUpdates => null,
            ContinueAsNewSuggestedReason::Unspecified => null,
        };
    }
}
```

The current reasons are:

- `HistorySizeTooLarge`
- `TooManyHistoryEvents`
- `TooManyUpdates`
- `Unspecified`

`WorkflowInfo` is refreshed from every Core activation, including
patch-only activations that do not otherwise enter Workflow code.

See
[`examples/truasync/upgrade_on_continue_as_new.php`](../examples/truasync/upgrade_on_continue_as_new.php)
for a complete Workflow definition.
