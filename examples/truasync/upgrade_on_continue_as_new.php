<?php

declare(strict_types=1);

/**
 * Workflow definition for an explicit Pinned -> Target Version rollover.
 *
 * Register this same Workflow type on every Deployment Version. Promote the
 * new version, then send the `upgrade` signal to a run pinned to the old
 * version. The next run's first task is routed to the Target Version.
 */

use Temporal\Common\Versioning\VersioningBehavior;
use Temporal\Workflow;
use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ContinueAsNewVersioningBehavior;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Workflow\WorkflowVersioningBehavior;

#[WorkflowInterface]
final class UpgradeableBillingWorkflow
{
    private bool $upgradeRequested = false;

    #[WorkflowMethod(name: 'BillingWorkflow')]
    #[WorkflowVersioningBehavior(VersioningBehavior::Pinned)]
    public function run(int $generation = 1): iterable
    {
        yield Workflow::await(fn(): bool => $this->upgradeRequested);

        if (!Workflow::getInfo()->targetWorkerDeploymentVersionChanged) {
            return "generation={$generation}: target version unchanged";
        }

        return Workflow::continueAsNew(
            type: 'BillingWorkflow',
            args: [$generation + 1],
            options: ContinueAsNewOptions::new()
                ->withInitialVersioningBehavior(
                    ContinueAsNewVersioningBehavior::AutoUpgrade,
                ),
        );
    }

    #[SignalMethod(name: 'upgrade')]
    public function upgrade(): void
    {
        $this->upgradeRequested = true;
    }
}
