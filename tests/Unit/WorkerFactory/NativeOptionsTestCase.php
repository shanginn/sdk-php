<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Unit\WorkerFactory;

use Temporal\Common\Versioning\VersioningBehavior;
use Temporal\Common\Versioning\WorkerDeploymentVersion;
use Temporal\Worker\TrueAsync\NullRpcConnection;
use Temporal\Worker\WorkerDeploymentOptions;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

final class NativeOptionsTestCase extends AbstractWorkerFactory
{
    public function testCoreWorkerOptionsMapNativeSettings(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $options = WorkerOptions::new()
            ->withMaxConcurrentActivityExecutionSize(25)
            ->withMaxConcurrentEagerActivityExecutionSize(12)
            ->withWorkerActivitiesPerSecond(4.5)
            ->withTaskQueueActivitiesPerSecond(3.5)
            ->withMaxHeartbeatThrottleInterval(2)
            ->withIdentity('native-worker')
            ->withDisableWorkflowWorker()
            ->withDeploymentOptions(
                WorkerDeploymentOptions::new()
                    ->withUseVersioning(true)
                    ->withVersion(WorkerDeploymentVersion::new('payments', '2026-07-29'))
                    ->withDefaultVersioningBehavior(VersioningBehavior::AutoUpgrade),
            );

        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');
        /** @var array<string, bool|float|int|string> $result */
        $result = $method->invoke($factory, $options);

        self::assertSame(4.5, $result['maxActivitiesPerSecond']);
        self::assertSame(3.5, $result['maxTaskQueueActivitiesPerSecond']);
        self::assertSame(2_000, $result['maxHeartbeatThrottleMs']);
        self::assertSame('native-worker', $result['identity']);
        self::assertTrue($result['disableWorkflows']);
        self::assertSame(0, $result['maxEagerActivityReservationsPerWorkflowTask']);
        self::assertSame(1, $result['versioningStrategy']);
        self::assertSame('payments', $result['deploymentName']);
        self::assertSame('2026-07-29', $result['buildId']);
        self::assertTrue($result['deploymentUseVersioning']);
        self::assertSame(VersioningBehavior::AutoUpgrade->value, $result['versioningBehavior']);
    }

    public function testEagerReservationLimitDefaultsToActivitySlots(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');

        $default = $method->invoke(
            $factory,
            WorkerOptions::new()->withMaxConcurrentActivityExecutionSize(25),
        );
        $limited = $method->invoke(
            $factory,
            WorkerOptions::new()
                ->withMaxConcurrentActivityExecutionSize(25)
                ->withMaxConcurrentEagerActivityExecutionSize(12),
        );
        $disabled = $method->invoke(
            $factory,
            WorkerOptions::new()->withDisableEagerActivities(),
        );

        self::assertSame(25, $default['maxEagerActivityReservationsPerWorkflowTask']);
        self::assertSame(12, $limited['maxEagerActivityReservationsPerWorkflowTask']);
        self::assertSame(0, $disabled['maxEagerActivityReservationsPerWorkflowTask']);
    }

    public function testLegacyBuildIdVersioningMapsToCore(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');
        $result = $method->invoke(
            $factory,
            WorkerOptions::new()
                ->withBuildID('legacy-v2')
                ->withUseBuildIDForVersioning(),
        );

        self::assertSame(2, $result['versioningStrategy']);
        self::assertSame('legacy-v2', $result['buildId']);
    }

    public function testDisabledDeploymentVersioningDoesNotRequireVersion(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');
        $result = $method->invoke(
            $factory,
            WorkerOptions::new()->withDeploymentOptions(
                WorkerDeploymentOptions::new()->withUseVersioning(false),
            ),
        );

        self::assertArrayNotHasKey('versioningStrategy', $result);
    }
}
