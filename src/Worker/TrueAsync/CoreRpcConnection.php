<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\Activity_task\ActivityCancelReason;
use Coresdk\Activity_task\Cancel;
use Coresdk\ActivityHeartbeat;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Worker\Transport\RPCConnectionInterface;
use TrueAsync\Temporal\Core\Worker as CoreWorker;

/**
 * The host-process RPC channel ActivityContext expects, backed by the Rust core.
 *
 * The reused SDK funnels activity heartbeats through an RPCConnectionInterface
 * (RoadRunner answered them in its Go host); here the call becomes a coresdk
 * ActivityHeartbeat handed to the core, which throttles it and ships it to the
 * server on its own threads — recording is synchronous and never parks the
 * activity coroutine.
 *
 * The same channel is how cancellation reaches activity code. The core delivers
 * a cancel as a separate cancel-variant ActivityTask on the poll stream (out of
 * band — the start task's coroutine is still running); the activity loop records
 * it here, and the response to the activity's next heartbeat carries the flag,
 * at which point ActivityContext throws the matching exception inside the
 * activity. This is the SDK's cooperative model: an activity that never
 * heartbeats never observes the cancel.
 */
final class CoreRpcConnection implements RPCConnectionInterface
{
    /** @var array<string, array{canceled: bool, paused: bool, reset: bool}> keyed by raw task token */
    private array $cancellations = [];

    public function __construct(private readonly CoreWorker $core) {}

    public function call(string $method, $payload): mixed
    {
        if ($method !== 'temporal.RecordActivityHeartbeat') {
            throw new \LogicException("RPC method not wired to the core (called: {$method})");
        }

        $taskToken = \base64_decode((string) ($payload['taskToken'] ?? ''), true);
        if ($taskToken === false || $taskToken === '') {
            throw new \LogicException('RecordActivityHeartbeat: missing or invalid task token');
        }

        $heartbeat = (new ActivityHeartbeat())->setTaskToken($taskToken);

        $details = \base64_decode((string) ($payload['details'] ?? ''), true);
        if ($details !== false && $details !== '') {
            $payloads = new Payloads();
            $payloads->mergeFromString($details);
            $heartbeat->setDetails(\iterator_to_array($payloads->getPayloads()));
        }

        $this->core->recordActivityHeartbeat($heartbeat->serializeToString());

        return $this->cancellations[$taskToken] ?? [];
    }

    /**
     * Record a cancel-variant activity task; the response to this token's next
     * heartbeat reports it. The details flags are authoritative when present;
     * the reason enum is the fallback. Whatever the cause (cancel request, not
     * found, timed out, worker shutdown), a cancel task means the result is no
     * longer wanted, so an unflagged cause still reports as canceled.
     */
    public function markCancellation(string $taskToken, Cancel $cancel): void
    {
        $details = $cancel->getDetails();

        if ($details !== null) {
            $paused = $details->getIsPaused();
            $reset = $details->getIsReset();
        } else {
            $paused = $cancel->getReason() === ActivityCancelReason::PAUSED;
            $reset = $cancel->getReason() === ActivityCancelReason::RESET;
        }

        $this->cancellations[$taskToken] = [
            'canceled' => !$paused && !$reset,
            'paused' => $paused,
            'reset' => $reset,
        ];
    }

    /** Drop a token's pending state once its activity has completed. */
    public function forget(string $taskToken): void
    {
        unset($this->cancellations[$taskToken]);
    }
}
