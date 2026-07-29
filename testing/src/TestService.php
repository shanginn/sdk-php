<?php

declare(strict_types=1);

namespace Temporal\Testing;

use Carbon\Carbon;
use Google\Protobuf\Duration;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Timestamp;
use Temporal\Api\Testservice\V1\GetCurrentTimeResponse;
use Temporal\Api\Testservice\V1\LockTimeSkippingResponse;
use Temporal\Api\Testservice\V1\LockTimeSkippingRequest;
use Temporal\Api\Testservice\V1\SleepRequest;
use Temporal\Api\Testservice\V1\SleepResponse;
use Temporal\Api\Testservice\V1\SleepUntilRequest;
use Temporal\Api\Testservice\V1\UnlockTimeSkippingRequest;
use Temporal\Api\Testservice\V1\UnlockTimeSkippingResponse;
use Temporal\Internal\Transport\NativeUnaryClient;
use TrueAsync\Temporal\Core\Connection;

final class TestService
{
    private const RESPONSES = [
        'LockTimeSkipping' => LockTimeSkippingResponse::class,
        'UnlockTimeSkipping' => UnlockTimeSkippingResponse::class,
        'Sleep' => SleepResponse::class,
        'SleepUntil' => SleepResponse::class,
        'UnlockTimeSkippingWithSleep' => SleepResponse::class,
        'GetCurrentTime' => GetCurrentTimeResponse::class,
    ];

    private NativeUnaryClient $client;
    private int $lockDelta = 0;

    public function __construct(Connection $connection)
    {
        $this->client = new NativeUnaryClient($connection, NativeUnaryClient::SERVICE_TEST);
    }

    public static function create(string $host): self
    {
        return new self(new Connection($host));
    }

    /**
     * Net lock/unlock delta applied through this instance since it was created.
     *
     * `unlockTimeSkippingWithSleep` is balanced (it decrements and increments the server counter),
     * so it does not change this value. A non-zero delta means a caller leaked a lock/unlock pair.
     */
    public function lockDelta(): int
    {
        return $this->lockDelta;
    }

    /**
     * Increments Time Locking Counter by one.
     *
     * If Time Locking Counter is positive, time skipping is locked (disabled).
     * When time skipping is disabled, the time in test server is moving normally, with a real time pace.
     * Test Server is typically started with locked time skipping and Time Locking Counter = 1.
     *
     * lockTimeSkipping and unlockTimeSkipping calls are counted.
     */
    public function lockTimeSkipping(): void
    {
        $this->invoke('LockTimeSkipping', new LockTimeSkippingRequest());
        ++$this->lockDelta;
    }

    /**
     * Decrements Time Locking Counter by one.
     *
     * If the counter reaches 0, it unlocks time skipping and fast forwards time.
     * LockTimeSkipping and UnlockTimeSkipping calls are counted. Calling UnlockTimeSkipping does not
     * guarantee that time is going to be fast forwarded as another lock can be holding it.
     *
     * Time Locking Counter can't be negative, unbalanced calls to unlockTimeSkipping will lead to a failure.
     */
    public function unlockTimeSkipping(): void
    {
        $this->invoke('UnlockTimeSkipping', new UnlockTimeSkippingRequest());
        --$this->lockDelta;
    }

    /**
     * Decreases time locking counter by one and increases it back.
     * Once the Test Server Time advances by the duration specified in the request.
     *
     * This call returns only when the Test Server Time advances by the specified duration.
     *
     * If it is called when Time Locking Counter is
     *   - more than 1 and no other unlocks are coming in, rpc call will block for the specified duration, time will not be fast forwarded.
     *   - 1, it will lead to fast forwarding of the time by the duration specified in the request and quick return of this rpc call.
     *   - 0 will lead to rpc call failure same way as an unbalanced unlockTimeSkipping.
     */
    public function unlockTimeSkippingWithSleep(int $seconds): void
    {
        $duration = (new Duration())->setSeconds($seconds);
        $request = (new SleepRequest())->setDuration($duration);
        $this->invoke('UnlockTimeSkippingWithSleep', $request);
    }

    /**
     * This call returns only when the Test Server Time advances by the specified duration.
     * This is an EXPERIMENTAL API.
     */
    public function sleep(int $seconds): void
    {
        $duration = (new Duration())->setSeconds($seconds);
        $request = (new SleepRequest())->setDuration($duration);
        $this->invoke('Sleep', $request);
    }

    /**
     * This call returns only when the Test Server Time advances to the specified timestamp.
     * If the current Test Server Time is beyond the specified timestamp, returns immediately.
     * This is an EXPERIMENTAL API.
     */
    public function sleepUntil(int $timestamp): void
    {
        $request = (new SleepUntilRequest())->setTimestamp((new Timestamp())->setSeconds($timestamp));
        $this->invoke('SleepUntil', $request);
    }

    /**
     * GetCurrentTime returns the current Temporal Test Server time
     */
    public function getCurrentTime(): Carbon
    {
        /** @var GetCurrentTimeResponse $result */
        $result = $this->invoke('GetCurrentTime', new GPBEmpty());
        return Carbon::createFromTimestamp($result->getTime()?->getSeconds() ?? 0);
    }

    private function invoke(string $method, object $request): object
    {
        $responseClass = self::RESPONSES[$method]
            ?? throw new \LogicException("Unknown Temporal test-service method {$method}.");

        return $this->client->call($method, $request, $responseClass);
    }
}
