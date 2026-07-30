<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\Tuning;

use PHPUnit\Framework\TestCase;
use Temporal\Worker\Tuning\CompositeTuner;
use Temporal\Worker\Tuning\FixedSizeSlotSupplier;
use Temporal\Worker\Tuning\PollerBehaviorAutoscaling;
use Temporal\Worker\Tuning\PollerBehaviorSimpleMaximum;
use Temporal\Worker\Tuning\ResourceBasedSlotConfig;
use Temporal\Worker\Tuning\ResourceBasedSlotSupplier;
use Temporal\Worker\Tuning\ResourceBasedTuner;
use Temporal\Worker\Tuning\ResourceBasedTunerConfig;

final class WorkerTuningTestCase extends TestCase
{
    public function testFixedSizeSupplierIsImmutableAndPositive(): void
    {
        $supplier = new FixedSizeSlotSupplier(17);

        self::assertSame(17, $supplier->slots);

        $this->expectException(\Error::class);
        $supplier->slots = 18;
    }

    public function testFixedSizeSupplierRejectsEmptyCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one slot');

        new FixedSizeSlotSupplier(0);
    }

    public function testResourceBasedConfigurationValidation(): void
    {
        self::assertSame(
            20,
            (new ResourceBasedSlotConfig(minimumSlots: 5, maximumSlots: 20))->maximumSlots,
        );

        foreach ([
            static fn(): object => new ResourceBasedTunerConfig(0.0, 0.8),
            static fn(): object => new ResourceBasedTunerConfig(0.8, 1.01),
            static fn(): object => new ResourceBasedSlotConfig(minimumSlots: 0),
            static fn(): object => new ResourceBasedSlotConfig(maximumSlots: 0),
            static fn(): object => new ResourceBasedSlotConfig(rampThrottleMs: -1),
            static fn(): object => new ResourceBasedSlotConfig(minimumSlots: 10, maximumSlots: 9),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid resource configuration was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testResourceBasedTunerProvidesAllFourSuppliers(): void
    {
        $config = new ResourceBasedTunerConfig(0.75, 0.85);
        $tuner = new ResourceBasedTuner(
            $config,
            workflowConfig: new ResourceBasedSlotConfig(minimumSlots: 3, maximumSlots: 30),
        );

        self::assertInstanceOf(ResourceBasedSlotSupplier::class, $tuner->workflowSlotSupplier());
        self::assertInstanceOf(ResourceBasedSlotSupplier::class, $tuner->activitySlotSupplier());
        self::assertInstanceOf(ResourceBasedSlotSupplier::class, $tuner->localActivitySlotSupplier());
        self::assertInstanceOf(ResourceBasedSlotSupplier::class, $tuner->nexusSlotSupplier());
        self::assertSame($config, $tuner->nexusSlotSupplier()->tunerConfig);
    }

    public function testCompositeTunerRejectsDifferentResourceControllers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same tuner configuration');

        new CompositeTuner(
            new ResourceBasedSlotSupplier(
                new ResourceBasedSlotConfig(),
                new ResourceBasedTunerConfig(0.7, 0.8),
            ),
            new FixedSizeSlotSupplier(10),
            new ResourceBasedSlotSupplier(
                new ResourceBasedSlotConfig(),
                new ResourceBasedTunerConfig(0.8, 0.8),
            ),
            new FixedSizeSlotSupplier(10),
        );
    }

    public function testPollerBehaviorValidation(): void
    {
        self::assertSame(5, (new PollerBehaviorSimpleMaximum())->maximum);

        $autoscaling = new PollerBehaviorAutoscaling(minimum: 2, maximum: 20, initial: 4);
        self::assertSame(2, $autoscaling->minimum);
        self::assertSame(20, $autoscaling->maximum);
        self::assertSame(4, $autoscaling->initial);

        foreach ([
            static fn(): object => new PollerBehaviorSimpleMaximum(0),
            static fn(): object => new PollerBehaviorAutoscaling(minimum: 0),
            static fn(): object => new PollerBehaviorAutoscaling(minimum: 5, maximum: 4, initial: 5),
            static fn(): object => new PollerBehaviorAutoscaling(minimum: 2, maximum: 4, initial: 1),
            static fn(): object => new PollerBehaviorAutoscaling(minimum: 2, maximum: 4, initial: 5),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid poller behavior was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
