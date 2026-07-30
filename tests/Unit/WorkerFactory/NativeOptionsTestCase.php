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
        $result = $method->invoke($factory, $options, false);

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
        self::assertFalse($result['enableNexus']);
        self::assertArrayNotHasKey('nexusSlots', $result);
        self::assertArrayNotHasKey('nexusPollers', $result);
    }

    public function testEagerReservationLimitDefaultsToActivitySlots(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');

        $default = $method->invoke(
            $factory,
            WorkerOptions::new()->withMaxConcurrentActivityExecutionSize(25),
            false,
        );
        $limited = $method->invoke(
            $factory,
            WorkerOptions::new()
                ->withMaxConcurrentActivityExecutionSize(25)
                ->withMaxConcurrentEagerActivityExecutionSize(12),
            false,
        );
        $disabled = $method->invoke(
            $factory,
            WorkerOptions::new()->withDisableEagerActivities(),
            false,
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
            false,
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
            false,
        );

        self::assertArrayNotHasKey('versioningStrategy', $result);
    }

    public function testNexusOptionsAreOnlyPassedWhenNexusServiceIsEnabled(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');
        $options = WorkerOptions::new()
            ->withMaxConcurrentNexusTaskExecutionSize(17)
            ->withMaxConcurrentNexusTaskPollers(3);

        $disabled = $method->invoke($factory, $options, false);
        $enabled = $method->invoke($factory, $options, true);

        self::assertFalse($disabled['enableNexus']);
        self::assertArrayNotHasKey('nexusSlots', $disabled);
        self::assertArrayNotHasKey('nexusPollers', $disabled);

        self::assertTrue($enabled['enableNexus']);
        self::assertSame(17, $enabled['nexusSlots']);
        self::assertSame(3, $enabled['nexusPollers']);
    }

    public function testEnabledNexusUsesNativeDefaultsForUnsetLimits(): void
    {
        $factory = WorkerFactory::create(rpc: new NullRpcConnection());
        $method = new \ReflectionMethod($factory, 'coreWorkerOptions');

        $result = $method->invoke($factory, WorkerOptions::new(), true);

        self::assertTrue($result['enableNexus']);
        self::assertSame(100, $result['nexusSlots']);
        self::assertSame(1, $result['nexusPollers']);
    }
}
