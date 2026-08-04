<?php

declare(strict_types=1);

/**
 * Live integration check for Upgrade-on-Continue-As-New.
 *
 * The first run is pinned to deployment build v1. After v2 becomes Current, a
 * signal wakes v1, verifies that Core reports the changed Target Version, and
 * continues-as-new with AutoUpgrade. The next run must execute on v2.
 *
 *   php -d extension=temporal.so tests/truasync/workflow_upgrade_continue_as_new.php [address]
 *
 * Exits 0 on pass or skip (no server), 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Api\Workflowservice\V1\SetWorkerDeploymentCurrentVersionRequest;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\Versioning\VersioningBehavior;
use Temporal\Workflow;
use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ContinueAsNewVersioningBehavior;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Workflow\WorkflowVersioningBehavior;
use Temporal\Worker\TrueAsync\TemporalWorker;
use TrueAsync\Temporal\Core\Connection;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

use function Async\await;
use function Async\delay;
use function Async\spawn;

#[Workflow\WorkflowInterface]
final class UpgradeOnContinueAsNewV1
{
    private bool $upgrade = false;

    #[Workflow\SignalMethod(name: 'upgrade')]
    public function upgrade(): void
    {
        $this->upgrade = true;
    }

    #[WorkflowMethod(name: 'TrueAsyncUpgradeOnContinueAsNew')]
    #[WorkflowVersioningBehavior(VersioningBehavior::Pinned)]
    public function run(int $generation = 1)
    {
        Workflow::await(fn(): bool => $this->upgrade);

        $targetChanged = Workflow::getInfo()->targetWorkerDeploymentVersionChanged;

        return Workflow::continueAsNew(
            'TrueAsyncUpgradeOnContinueAsNew',
            [$generation + 1, $targetChanged],
            ContinueAsNewOptions::new()->withInitialVersioningBehavior(
                ContinueAsNewVersioningBehavior::AutoUpgrade,
            ),
        );
    }
}

#[Workflow\WorkflowInterface]
final class UpgradeOnContinueAsNewV2
{
    #[WorkflowMethod(name: 'TrueAsyncUpgradeOnContinueAsNew')]
    #[WorkflowVersioningBehavior(VersioningBehavior::Pinned)]
    public function run(int $generation = 1, bool $targetChanged = false): string
    {
        return \sprintf('v2:generation=%d,targetChanged=%s', $generation, $targetChanged ? 'true' : 'false');
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

$suffix = \bin2hex(\random_bytes(4));
$taskQueue = "truasync-upgrade-can-{$suffix}";
$deployment = "truasync-upgrade-can-{$suffix}";
$wfId = "truasync-upgrade-can-{$suffix}";

[$result, $recordedBehavior] = await(spawn(
    static function () use ($address, $taskQueue, $deployment, $wfId): array {
        $workerOptions = static fn(string $buildId): array => [
            'versioningStrategy' => 1,
            'deploymentName' => $deployment,
            'buildId' => $buildId,
            'deploymentUseVersioning' => true,
            'versioningBehavior' => VersioningBehavior::Pinned->value,
        ];

        $coreV1 = new CoreWorker(
            new Connection($address),
            $taskQueue,
            'default',
            10,
            $workerOptions('v1'),
        );
        $workerV1 = new TemporalWorker($coreV1, $taskQueue);
        $workerV1->registerWorkflowTypes(UpgradeOnContinueAsNewV1::class);
        $loopV1 = spawn(static fn() => $workerV1->run());

        $workerV2 = null;
        $loopV2 = null;

        try {
            $service = TrueAsyncServiceClient::fromCore(new Connection($address));
            $setCurrent = static function (string $buildId) use ($service, $deployment): void {
                $service->SetWorkerDeploymentCurrentVersion(
                    (new SetWorkerDeploymentCurrentVersionRequest())
                        ->setNamespace('default')
                        ->setDeploymentName($deployment)
                        ->setBuildId($buildId)
                        ->setIgnoreMissingTaskQueues(true)
                        ->setAllowNoPollers(true),
                );
            };

            // Give Core one poll cycle to register the Deployment Version.
            delay(250);
            $setCurrent('v1');

            $client = WorkflowClient::create(
                TrueAsyncServiceClient::fromCore(new Connection($address)),
            );
            $stub = $client->newUntypedWorkflowStub(
                'TrueAsyncUpgradeOnContinueAsNew',
                WorkflowOptions::new()
                    ->withTaskQueue($taskQueue)
                    ->withWorkflowId($wfId),
            );
            $run = $client->start($stub, 1);
            $initialExecution = new WorkflowExecution(
                $run->getExecution()->getID(),
                $run->getExecution()->getRunID(),
            );

            $coreV2 = new CoreWorker(
                new Connection($address),
                $taskQueue,
                'default',
                10,
                $workerOptions('v2'),
            );
            $workerV2 = new TemporalWorker($coreV2, $taskQueue);
            $workerV2->registerWorkflowTypes(UpgradeOnContinueAsNewV2::class);
            $loopV2 = spawn(static fn() => $workerV2->run());

            delay(250);
            $setCurrent('v2');
            // Routing configuration propagates asynchronously to task queues.
            // Wait before creating the signal Workflow Task whose activation
            // must carry target_worker_deployment_version_changed.
            delay(1_000);
            $stub->signal('upgrade');

            $result = (string) $run->getResult(null, 30);

            $recordedBehavior = null;
            foreach ($client->getWorkflowHistory($initialExecution) as $event) {
                if (!$event->hasWorkflowExecutionContinuedAsNewEventAttributes()) {
                    continue;
                }

                $recordedBehavior = $event
                    ->getWorkflowExecutionContinuedAsNewEventAttributes()
                    ?->getInitialVersioningBehavior();
                break;
            }

            return [$result, $recordedBehavior];
        } finally {
            $workerV1->shutdown();
            $workerV2?->shutdown();
            await($loopV1);
            $loopV2 === null or await($loopV2);
        }
    },
));

$expected = 'v2:generation=2,targetChanged=true';
if ($result !== $expected) {
    \fwrite(\STDERR, "FAIL: unexpected workflow result: {$result} (wanted {$expected})\n");
    exit(1);
}

// Temporal Server 1.31.2 routes the new run correctly but does not echo this
// command field into WorkflowExecutionContinuedAsNewEventAttributes yet. Newer
// servers report AutoUpgrade; accept UNSPECIFIED from that pinned CI baseline.
if (!\in_array(
    $recordedBehavior,
    [
        ContinueAsNewVersioningBehavior::Unspecified->value,
        ContinueAsNewVersioningBehavior::AutoUpgrade->value,
    ],
    true,
)) {
    \fwrite(
        \STDERR,
        "FAIL: history recorded invalid initial versioning behavior {$recordedBehavior}\n",
    );
    exit(1);
}

\fwrite(
    \STDOUT,
    "PASS: workflow={$wfId} result={$result} initialBehavior={$recordedBehavior}\n",
);
exit(0);
