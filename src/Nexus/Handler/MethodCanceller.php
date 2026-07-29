<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Nexus\Handler;

use Temporal\Exception\TransportException;
use Temporal\Worker\Environment\EnvironmentInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;

/**
 * Idempotent cancellation of an in-flight handler *method* (not the Nexus
 * operation). Until cancellation is observed, each state inspection polls
 * RoadRunner when an RPC connection and invocation ID are attached. Optional
 * `$deadline` auto-trips locally on the next inspection. Listeners are notified
 * at most once, by identity; registration polls once but does not start a
 * background watcher.
 */
final class MethodCanceller
{
    private const POLL_RPC = 'temporal.GetNexusMethodCancellation';

    private ?string $reason = null;

    /** @var \SplObjectStorage<MethodCancellationListenerInterface, null> */
    private readonly \SplObjectStorage $listeners;

    public function __construct(
        private readonly EnvironmentInterface $env,
        private readonly ?\DateTimeImmutable $deadline = null,
        private readonly ?RPCConnectionInterface $rpc = null,
        private readonly int $invocationId = 0,
    ) {
        $this->listeners = new \SplObjectStorage();
    }

    /**
     * @throws TransportException When RoadRunner cannot be reached.
     * @throws \UnexpectedValueException When RoadRunner returns a malformed response.
     */
    public function isCancelled(): bool
    {
        $this->refresh();
        return $this->reason !== null;
    }

    /**
     * @throws TransportException When RoadRunner cannot be reached.
     * @throws \UnexpectedValueException When RoadRunner returns a malformed response.
     */
    public function getReason(): ?string
    {
        $this->refresh();
        return $this->reason;
    }

    /**
     * Idempotent. Listeners run in registration order. Not reentrant.
     */
    public function cancel(string $reason): void
    {
        if ($this->reason !== null) {
            return;
        }
        $this->reason = $reason;
        foreach ($this->listeners as $listener) {
            $listener->cancelled();
        }
    }

    /**
     * Polls once. If cancellation is observed, the listener is invoked
     * synchronously and not stored. Registration does not start background
     * polling; a handler that blocks must continue inspecting cancellation.
     *
     * @throws TransportException When RoadRunner cannot be reached.
     * @throws \UnexpectedValueException When RoadRunner returns a malformed response.
     */
    public function addListener(MethodCancellationListenerInterface $listener): void
    {
        $this->refresh();
        if ($this->reason !== null) {
            $listener->cancelled();
            return;
        }
        $this->listeners->offsetSet($listener);
    }

    public function removeListener(MethodCancellationListenerInterface $listener): void
    {
        $this->listeners->offsetUnset($listener);
    }

    private static function formatDeadlineReason(\DateTimeImmutable $deadline): string
    {
        return \sprintf('deadline exceeded (%s)', $deadline->format(\DATE_ATOM));
    }

    private function refresh(): void
    {
        if ($this->reason !== null) {
            return;
        }

        $this->checkDeadline();
        if ($this->reason !== null || $this->rpc === null || $this->invocationId === 0) {
            return;
        }

        $response = $this->rpc->call(self::POLL_RPC, ['invocationId' => $this->invocationId]);
        if (!\is_array($response)) {
            throw new \UnexpectedValueException(\sprintf(
                'Malformed %s RPC response: expected array, got %s.',
                self::POLL_RPC,
                \get_debug_type($response),
            ));
        }
        if (!\array_key_exists('cancelled', $response)) {
            throw new \UnexpectedValueException(\sprintf(
                'Malformed %s RPC response: required "cancelled" field is missing.',
                self::POLL_RPC,
            ));
        }
        if (!\is_bool($response['cancelled'])) {
            throw new \UnexpectedValueException(\sprintf(
                'Malformed %s RPC response: "cancelled" must be bool, got %s.',
                self::POLL_RPC,
                \get_debug_type($response['cancelled']),
            ));
        }
        if (\array_key_exists('reason', $response) && !\is_string($response['reason'])) {
            throw new \UnexpectedValueException(\sprintf(
                'Malformed %s RPC response: "reason" must be string when present, got %s.',
                self::POLL_RPC,
                \get_debug_type($response['reason']),
            ));
        }

        if ($response['cancelled']) {
            $this->cancel($response['reason'] ?? '');
        }
    }

    private function checkDeadline(): void
    {
        if ($this->reason !== null || $this->deadline === null) {
            return;
        }
        if ($this->deadline > $this->env->now()) {
            return;
        }
        $this->cancel(self::formatDeadlineReason($this->deadline));
    }
}
