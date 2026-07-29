<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Workflow;

use Google\Protobuf\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use Temporal\Nexus\Exception\InvalidArgumentException;
use Temporal\Tests\Unit\DTO\AbstractDTOMarshalling;
use Temporal\Workflow\NexusOperationCancellationType;
use Temporal\Workflow\NexusOperationOptions;

/**
 * @group unit
 * @group nexus
 */
#[CoversClass(NexusOperationOptions::class)]
final class NexusOperationOptionsTestCase extends AbstractDTOMarshalling
{
    public function testNewHasEmptyDefaults(): void
    {
        $options = NexusOperationOptions::new();

        self::assertSame('', $options->endpoint);
        self::assertSame('', $options->service);
        self::assertSame('', $options->summary);
        self::assertSame(0, $options->scheduleToCloseTimeout->s);
    }

    public function testWithEndpointSetsEndpoint(): void
    {
        $options = NexusOperationOptions::new()->withEndpoint('endpoint-1');

        self::assertSame('endpoint-1', $options->endpoint);
    }

    public function testWithEndpointAcceptsNonAsciiName(): void
    {
        $options = NexusOperationOptions::new()->withEndpoint('платежи endpoint');

        self::assertSame('платежи endpoint', $options->endpoint);
    }

    public function testWithEndpointRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nexus Endpoint must not be empty');

        NexusOperationOptions::new()->withEndpoint('');
    }

    public function testWithEndpointRejectsTemporalReservedPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved prefix `__temporal_`');

        NexusOperationOptions::new()->withEndpoint('__temporal_internal');
    }

    public function testWithEndpointIsImmutable(): void
    {
        $original = NexusOperationOptions::new();
        $updated = $original->withEndpoint('endpoint-2');

        self::assertNotSame($original, $updated);
        self::assertSame('', $original->endpoint, 'Original must stay pristine');
        self::assertSame('endpoint-2', $updated->endpoint);
    }

    public function testWithServiceSetsService(): void
    {
        $options = NexusOperationOptions::new()->withService('MyService');

        self::assertSame('MyService', $options->service);
    }

    public function testWithServiceAcceptsArbitraryNonEmptyNexusName(): void
    {
        $options = NexusOperationOptions::new()->withService("платежи / v1\n");

        self::assertSame("платежи / v1\n", $options->service);
    }

    public function testWithServiceRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Name must not be empty');

        NexusOperationOptions::new()->withService('');
    }

    public function testWithServiceRejectsTemporalReservedPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved prefix `__temporal_`');

        NexusOperationOptions::new()->withService('__temporal_internal');
    }

    public function testWithSummarySetsSummaryImmutably(): void
    {
        $original = NexusOperationOptions::new();
        $updated = $original->withSummary('Capture the payment');

        self::assertNotSame($original, $updated);
        self::assertSame('', $original->summary);
        self::assertSame('Capture the payment', $updated->summary);
    }

    public function testWithSummaryAcceptsEmptyStringAsUnset(): void
    {
        $options = NexusOperationOptions::new()
            ->withSummary('Capture the payment')
            ->withSummary('');

        self::assertSame('', $options->summary);
    }

    public function testMarshalsSummaryUnderWireKey(): void
    {
        $marshalled = $this->marshal(
            NexusOperationOptions::new()->withSummary('Capture the payment'),
        );

        self::assertSame('Capture the payment', $marshalled['summary']);
    }

    public function testWithScheduleToCloseTimeoutAcceptsSeconds(): void
    {
        $options = NexusOperationOptions::new()->withScheduleToCloseTimeout(30);

        self::assertSame(30, $options->scheduleToCloseTimeout->s);
    }

    public function testWithScheduleToCloseTimeoutAcceptsNullAsUnset(): void
    {
        $options = NexusOperationOptions::new()->withScheduleToCloseTimeout(null);

        self::assertSame(0.0, (float) $options->scheduleToCloseTimeout->totalSeconds);
    }

    public function testWithScheduleToCloseTimeoutAcceptsProtoDuration(): void
    {
        $duration = (new Duration())->setSeconds(3)->setNanos(500_000_000);

        $options = NexusOperationOptions::new()->withScheduleToCloseTimeout($duration);

        self::assertSame(3.5, $options->scheduleToCloseTimeout->totalSeconds);
    }

    public function testWithScheduleToStartTimeoutAcceptsSeconds(): void
    {
        $options = NexusOperationOptions::new()->withScheduleToStartTimeout(5);

        self::assertSame(5, $options->scheduleToStartTimeout->s);
    }

    public function testWithScheduleToStartTimeoutIsImmutable(): void
    {
        $original = NexusOperationOptions::new();
        $updated = $original->withScheduleToStartTimeout(5);

        self::assertNotSame($original, $updated);
        self::assertSame(0, $original->scheduleToStartTimeout->s, 'Original must stay pristine');
        self::assertSame(5, $updated->scheduleToStartTimeout->s);
    }

    public function testWithStartToCloseTimeoutAcceptsSeconds(): void
    {
        $options = NexusOperationOptions::new()->withStartToCloseTimeout(10);

        self::assertSame(10, $options->startToCloseTimeout->s);
    }

    public function testWithStartToCloseTimeoutIsImmutable(): void
    {
        $original = NexusOperationOptions::new();
        $updated = $original->withStartToCloseTimeout(10);

        self::assertNotSame($original, $updated);
        self::assertSame(0, $original->startToCloseTimeout->s, 'Original must stay pristine');
        self::assertSame(10, $updated->startToCloseTimeout->s);
    }

    public function testTimeoutSettersRejectUnsupportedTypesAtRuntime(): void
    {
        $setters = [
            static fn(NexusOperationOptions $options): NexusOperationOptions =>
                $options->withScheduleToCloseTimeout([]),
            static fn(NexusOperationOptions $options): NexusOperationOptions =>
                $options->withScheduleToStartTimeout(new \stdClass()),
            static fn(NexusOperationOptions $options): NexusOperationOptions =>
                $options->withStartToCloseTimeout(false),
        ];

        foreach ($setters as $setter) {
            try {
                $setter(NexusOperationOptions::new());
                self::fail('Expected invalid timeout to be rejected.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('must be a valid duration', $e->getMessage());
            }
        }
    }

    public function testTimeoutSetterRejectsUnparseableDurationAtRuntime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a valid duration');

        NexusOperationOptions::new()->withScheduleToCloseTimeout('definitely-not-a-duration');
    }

    public function testTimeoutSettersRejectNegativeDurationsAtRuntime(): void
    {
        $inverted = new \DateInterval('PT1S');
        $inverted->invert = 1;

        $negativeTimeouts = [
            'negative integer' => -1,
            'negative float' => -0.5,
            'negative string' => '-1 second',
            'inverted DateInterval' => $inverted,
            'negative protobuf Duration' => (new Duration())->setSeconds(-1),
        ];
        $setters = [
            'Schedule-to-Close' => static fn(NexusOperationOptions $options, mixed $timeout): NexusOperationOptions =>
                $options->withScheduleToCloseTimeout($timeout),
            'Schedule-to-Start' => static fn(NexusOperationOptions $options, mixed $timeout): NexusOperationOptions =>
                $options->withScheduleToStartTimeout($timeout),
            'Start-to-Close' => static fn(NexusOperationOptions $options, mixed $timeout): NexusOperationOptions =>
                $options->withStartToCloseTimeout($timeout),
        ];

        foreach ($setters as $setterName => $setter) {
            foreach ($negativeTimeouts as $timeoutName => $timeout) {
                try {
                    $setter(NexusOperationOptions::new(), $timeout);
                    self::fail("Expected {$setterName} {$timeoutName} timeout to be rejected.");
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('must not be negative', $e->getMessage());
                }
            }
        }
    }

    public function testMarshalsNewTimeoutsUnderWireKeys(): void
    {
        $options = NexusOperationOptions::new()
            ->withScheduleToStartTimeout(5)
            ->withStartToCloseTimeout(10);

        $marshalled = $this->marshal($options);

        self::assertArrayHasKey('scheduleToStartTimeout', $marshalled);
        self::assertArrayHasKey('startToCloseTimeout', $marshalled);
        self::assertSame(5_000_000_000, $marshalled['scheduleToStartTimeout']);
        self::assertSame(10_000_000_000, $marshalled['startToCloseTimeout']);
    }

    public function testCancellationTypeDefaultsToUnspecified(): void
    {
        $options = NexusOperationOptions::new();

        self::assertSame(NexusOperationCancellationType::Unspecified, $options->cancellationType);
    }

    public function testWithCancellationTypeAcceptsEnum(): void
    {
        $options = NexusOperationOptions::new()
            ->withCancellationType(NexusOperationCancellationType::TryCancel);

        self::assertSame(NexusOperationCancellationType::TryCancel, $options->cancellationType);
    }

    public function testWithCancellationTypeAcceptsInt(): void
    {
        $options = NexusOperationOptions::new()
            ->withCancellationType(NexusOperationCancellationType::WaitCompleted->value);

        self::assertSame(NexusOperationCancellationType::WaitCompleted, $options->cancellationType);
    }

    public function testWithCancellationTypeIsImmutable(): void
    {
        $original = NexusOperationOptions::new();
        $updated = $original->withCancellationType(NexusOperationCancellationType::Abandon);

        self::assertNotSame($original, $updated);
        self::assertSame(
            NexusOperationCancellationType::Unspecified,
            $original->cancellationType,
            'Original must stay pristine',
        );
        self::assertSame(NexusOperationCancellationType::Abandon, $updated->cancellationType);
    }
}
