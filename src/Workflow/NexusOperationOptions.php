<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Workflow;

use Google\Protobuf\Duration;
use JetBrains\PhpStorm\Pure;
use Temporal\Internal\Marshaller\Meta\Marshal;
use Temporal\Internal\Marshaller\Type\DateIntervalType;
use Temporal\Internal\Marshaller\Type\EnumValueType;
use Temporal\Internal\Support\DateInterval;
use Temporal\Internal\Support\Options;
use Temporal\Nexus\Exception\InvalidArgumentException;
use Temporal\Nexus\Validation\ServiceNameValidator;
use Temporal\Nexus\Validation\TemporalNameValidator;

/**
 * Options for executing a Nexus operation from a workflow.
 *
 * @psalm-import-type DateIntervalValue from DateInterval
 */
final class NexusOperationOptions extends Options
{
    /**
     * Name of the Nexus Endpoint as registered in the Temporal server.
     */
    #[Marshal(name: 'endpoint')]
    public string $endpoint = '';

    /**
     * Service name to call. If empty, derived from the service interface.
     */
    #[Marshal(name: 'service')]
    public string $service = '';

    /**
     * Single-line fixed summary for this Nexus operation that will appear in UI/CLI.
     *
     * This can be in single-line Temporal Markdown format. An empty string means
     * that no summary is set.
     */
    #[Marshal(name: 'summary')]
    public string $summary = '';

    /**
     * Overall timeout for the Nexus operation.
     */
    #[Marshal(name: 'scheduleToCloseTimeout', type: DateIntervalType::class)]
    public \DateInterval $scheduleToCloseTimeout;

    /**
     * Maximum time the operation may wait to be started by the handler.
     */
    #[Marshal(name: 'scheduleToStartTimeout', type: DateIntervalType::class)]
    public \DateInterval $scheduleToStartTimeout;

    /**
     * Maximum time an asynchronous operation may take to complete after it has started.
     */
    #[Marshal(name: 'startToCloseTimeout', type: DateIntervalType::class)]
    public \DateInterval $startToCloseTimeout;

    /**
     * Behaviour applied when the caller workflow is cancelled.
     *
     * Defaults to {@see NexusOperationCancellationType::Unspecified}, which
     * the server treats as {@see NexusOperationCancellationType::WaitCompleted}
     * (the sdk-go default).
     *
     * @see NexusOperationCancellationType
     */
    #[Marshal(name: 'cancellationType', type: EnumValueType::class, of: NexusOperationCancellationType::class)]
    public NexusOperationCancellationType $cancellationType;

    public function __construct()
    {
        $this->scheduleToCloseTimeout = \Carbon\CarbonInterval::seconds(0);
        $this->scheduleToStartTimeout = \Carbon\CarbonInterval::seconds(0);
        $this->startToCloseTimeout = \Carbon\CarbonInterval::seconds(0);
        $this->cancellationType = NexusOperationCancellationType::Unspecified;
        parent::__construct();
    }

    /**
     * @param string $endpoint Must not be empty or use Temporal's reserved prefix.
     */
    #[Pure]
    public function withEndpoint(string $endpoint): self
    {
        if ($endpoint === '') {
            throw new InvalidArgumentException('Nexus Endpoint must not be empty');
        }
        TemporalNameValidator::assertNotReserved($endpoint, 'Nexus Endpoint');

        $self = clone $this;
        $self->endpoint = $endpoint;
        return $self;
    }

    /**
     * @param string $service Must not be empty or use Temporal's reserved prefix.
     */
    #[Pure]
    public function withService(string $service): self
    {
        ServiceNameValidator::assert($service);
        TemporalNameValidator::assertNotReserved($service, 'Service Name');

        $self = clone $this;
        $self->service = $service;
        return $self;
    }

    /**
     * Sets the single-line fixed summary displayed for this Nexus operation.
     *
     * Pass an empty string to clear the summary.
     */
    #[Pure]
    public function withSummary(string $summary): self
    {
        $self = clone $this;
        $self->summary = $summary;
        return $self;
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withScheduleToCloseTimeout($timeout): self
    {
        $timeout = self::parseTimeout($timeout, 'Schedule-to-Close timeout');

        $self = clone $this;
        $self->scheduleToCloseTimeout = $timeout;
        return $self;
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withScheduleToStartTimeout($timeout): self
    {
        $timeout = self::parseTimeout($timeout, 'Schedule-to-Start timeout');

        $self = clone $this;
        $self->scheduleToStartTimeout = $timeout;
        return $self;
    }

    /**
     * @psalm-suppress ImpureMethodCall
     *
     * @param DateIntervalValue $timeout
     */
    #[Pure]
    public function withStartToCloseTimeout($timeout): self
    {
        $timeout = self::parseTimeout($timeout, 'Start-to-Close timeout');

        $self = clone $this;
        $self->startToCloseTimeout = $timeout;
        return $self;
    }

    #[Pure]
    public function withCancellationType(NexusOperationCancellationType|int $type): self
    {
        if (\is_int($type)) {
            $type = NexusOperationCancellationType::from($type);
        }

        $self = clone $this;
        $self->cancellationType = $type;
        return $self;
    }

    /**
     * @psalm-suppress ImpureMethodCall
     */
    private static function parseTimeout(mixed $timeout, string $label): \Carbon\CarbonInterval
    {
        if (
            $timeout !== null
            && !$timeout instanceof Duration
            && !DateInterval::assert($timeout)
        ) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be a valid duration, got %s.',
                $label,
                \get_debug_type($timeout),
            ));
        }

        if (\is_string($timeout) && \preg_match('/\d/', $timeout) !== 1) {
            throw new InvalidArgumentException("{$label} must be a valid duration.");
        }

        if (
            ((\is_int($timeout) || \is_float($timeout)) && $timeout < 0)
            || ($timeout instanceof Duration
                && ($timeout->getSeconds() < 0 || $timeout->getNanos() < 0))
            || ($timeout instanceof \DateInterval && self::hasNegativeComponent($timeout))
            || (\is_string($timeout)
                && \preg_match('/(^|[\s,])-\s*(?:\d|\.\d)/', $timeout) === 1)
        ) {
            throw new InvalidArgumentException("{$label} must not be negative.");
        }

        try {
            $parsed = DateInterval::parse($timeout, DateInterval::FORMAT_SECONDS);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("{$label} must be a valid duration.", 0, $e);
        }

        // Carbon 2 and Carbon 3 expose signed intervals differently. Carbon 2
        // may keep invert=0 and store the sign on individual components, while
        // Carbon 3 reliably exposes signed computed totals. Check both native
        // DateInterval inversion and every component instead of depending on a
        // version-specific total* property.
        if (self::hasNegativeComponent($parsed)) {
            throw new InvalidArgumentException("{$label} must not be negative.");
        }

        return $parsed;
    }

    private static function hasNegativeComponent(\DateInterval $interval): bool
    {
        return $interval->invert === 1
            || $interval->y < 0
            || $interval->m < 0
            || $interval->d < 0
            || $interval->h < 0
            || $interval->i < 0
            || $interval->s < 0
            || $interval->f < 0;
    }
}
