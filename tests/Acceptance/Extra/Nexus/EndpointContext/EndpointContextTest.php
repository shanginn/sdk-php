<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus\EndpointContext;

use PHPUnit\Framework\Attributes\Test;
use Temporal\Nexus\Attribute\AsyncOperation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Handler\MethodCancellationListenerInterface;
use Temporal\Nexus\Handler\OperationCancelDetails;
use Temporal\Nexus\Handler\OperationContext;
use Temporal\Nexus\Handler\OperationHandlerInterface;
use Temporal\Nexus\Handler\OperationStartDetails;
use Temporal\Nexus\Handler\OperationStartResult;
use Temporal\Nexus\Nexus;
use Temporal\Nexus\OperationInfo;
use Temporal\Nexus\OperationState;
use Temporal\Tests\Acceptance\App\Attribute\Worker;
use Temporal\Tests\Acceptance\App\Runtime\State;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusEndpoints;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusHttpClient;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusWorkerOptions;
use Temporal\Worker\WorkerOptions;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[Worker(options: [self::class, 'workerOptions'])]
final class EndpointContextTest extends TestCase
{
    public static function workerOptions(): WorkerOptions
    {
        return NexusWorkerOptions::default();
    }

    #[Test]
    public function endpointNameIsAvailableDuringStartAndCancel(
        State $state,
        NexusEndpoints $endpoints,
        NexusHttpClient $http,
    ): void {
        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'nexus-endpoint-context');
        $marker = \sys_get_temp_dir() . '/temporal-nexus-endpoint-' . \bin2hex(\random_bytes(8));

        try {
            [$startStatus, $startBody] = $http->post(
                $endpoint,
                'EndpointContextService',
                'observe',
                $marker,
                ['Nexus-Callback-Url' => 'http://callback.example.local/done'],
            );

            self::assertSame(201, $startStatus, $startBody);
            $start = \json_decode($startBody, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($start);
            $token = $start['token'] ?? $start['operationToken'] ?? null;
            self::assertIsString($token);
            self::assertNotSame('', $token);

            [$cancelStatus, $cancelBody] = $http->cancel(
                $endpoint,
                'EndpointContextService',
                'observe',
                $token,
            );
            self::assertSame(202, $cancelStatus, $cancelBody);

            self::assertSame(
                $endpoint->name,
                self::readEndpointMarker($marker . '.start'),
                'Start dispatch must receive the endpoint name from Temporal.',
            );
            self::assertSame(
                $endpoint->name,
                self::readEndpointMarker($marker . '.cancel'),
                'Cancel dispatch must receive the endpoint name from Temporal.',
            );
        } finally {
            @\unlink($marker . '.start');
            @\unlink($marker . '.cancel');
        }
    }

    #[Test]
    public function cancelHandlerObservesRequestCancellationAcrossRoadRunnerProcesses(
        State $state,
        NexusEndpoints $endpoints,
        NexusHttpClient $http,
    ): void {
        $endpoint = $endpoints->register(
            $state->namespace,
            __NAMESPACE__,
            'nexus-cancel-method-cancellation',
        );
        $marker = \sys_get_temp_dir() . '/temporal-nexus-cancel-method-' . \bin2hex(\random_bytes(8)) . '.json';

        try {
            [$startStatus, $startBody] = $http->post(
                $endpoint,
                'EndpointContextService',
                'observeCancelableCancel',
                $marker,
                ['Nexus-Callback-Url' => 'http://callback.example.local/done'],
            );
            self::assertSame(201, $startStatus, $startBody);

            $start = \json_decode($startBody, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($start);
            $token = $start['token'] ?? $start['operationToken'] ?? null;
            self::assertIsString($token);
            self::assertNotSame('', $token);

            [$cancelStatus] = $http->cancel(
                $endpoint,
                'EndpointContextService',
                'observeCancelableCancel',
                $token,
                ['Request-Timeout' => '1s'],
            );
            self::assertSame(
                520,
                $cancelStatus,
                'the frontend must report the Cancel handler request deadline as upstream timeout',
            );

            $markerDeadline = \microtime(true) + 5.0;
            while (!\is_file($marker) && \microtime(true) < $markerDeadline) {
                \usleep(50_000);
            }
            self::assertFileExists(
                $marker,
                'the still-running PHP Cancel handler must observe Go context cancellation',
            );

            $observed = \json_decode((string) \file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($observed);
            self::assertSame(true, $observed['cancelled'] ?? null);
            self::assertSame(true, $observed['listenerCalled'] ?? null);
            self::assertIsString($observed['reason'] ?? null);
            self::assertNotSame('', $observed['reason']);
            self::assertSame($endpoint->name, $observed['endpoint'] ?? null);
        } finally {
            @\unlink($marker);
        }
    }

    private static function readEndpointMarker(string $path): string
    {
        $deadline = \microtime(true) + 5.0;
        while (!\is_file($path) && \microtime(true) < $deadline) {
            \usleep(50_000);
        }

        self::assertFileExists($path);
        return (string) \file_get_contents($path);
    }
}

#[Service(name: 'EndpointContextService')]
final class EndpointContextService
{
    #[AsyncOperation(input: 'string', output: 'string')]
    public function observe(): EndpointContextHandler
    {
        return new EndpointContextHandler();
    }

    #[AsyncOperation(input: 'string', output: 'string')]
    public function observeCancelableCancel(): CancelMethodCancellationHandler
    {
        return new CancelMethodCancellationHandler();
    }
}

final class EndpointContextHandler implements OperationHandlerInterface
{
    public function start(
        OperationContext $context,
        OperationStartDetails $details,
        mixed $param,
    ): OperationStartResult {
        \assert(\is_string($param));
        \file_put_contents($param . '.start', Nexus::getOperationContext()->endpoint, \LOCK_EX);

        return OperationStartResult::async(new OperationInfo(
            self::encodeToken($param),
            OperationState::Running,
        ));
    }

    public function cancel(
        OperationContext $context,
        OperationCancelDetails $details,
    ): void {
        $marker = self::decodeToken($details->operationToken);
        \file_put_contents($marker . '.cancel', Nexus::getOperationContext()->endpoint, \LOCK_EX);
    }

    private static function encodeToken(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeToken(string $value): string
    {
        $padding = (4 - \strlen($value) % 4) % 4;
        $decoded = \base64_decode(\strtr($value . \str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException('Invalid endpoint-context operation token.');
        }

        return $decoded;
    }
}

final class CancelMethodCancellationHandler implements OperationHandlerInterface
{
    public function start(
        OperationContext $context,
        OperationStartDetails $details,
        mixed $param,
    ): OperationStartResult {
        if (!\is_string($param) || $param === '') {
            throw new \InvalidArgumentException('Cancel-method marker must be a non-empty string.');
        }

        return OperationStartResult::async(new OperationInfo(
            self::encodeToken($param),
            OperationState::Running,
        ));
    }

    public function cancel(
        OperationContext $context,
        OperationCancelDetails $details,
    ): void {
        $marker = self::decodeToken($details->operationToken);
        $listener = new class implements MethodCancellationListenerInterface {
            public bool $called = false;

            public function cancelled(): void
            {
                $this->called = true;
            }
        };
        $context->addMethodCancellationListener($listener);

        $expiresAt = \microtime(true) + 10.0;
        do {
            if ($context->isMethodCancelled()) {
                self::writeMarker($marker, [
                    'cancelled' => true,
                    'listenerCalled' => $listener->called,
                    'reason' => $context->getMethodCancellationReason(),
                    'endpoint' => Nexus::getOperationContext()->endpoint,
                ]);
                return;
            }
            \usleep(50_000);
        } while (\microtime(true) < $expiresAt);

        self::writeMarker($marker, [
            'cancelled' => false,
            'listenerCalled' => $listener->called,
            'reason' => $context->getMethodCancellationReason(),
            'endpoint' => Nexus::getOperationContext()->endpoint,
        ]);
    }

    /**
     * @param array{cancelled: bool, listenerCalled: bool, reason: ?string, endpoint: string} $state
     */
    private static function writeMarker(string $marker, array $state): void
    {
        \file_put_contents(
            $marker,
            \json_encode($state, \JSON_THROW_ON_ERROR),
            \LOCK_EX,
        );
    }

    private static function encodeToken(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeToken(string $value): string
    {
        $padding = (4 - \strlen($value) % 4) % 4;
        $decoded = \base64_decode(\strtr($value . \str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException('Invalid cancel-method operation token.');
        }

        return $decoded;
    }
}

#[WorkflowInterface]
final class EndpointContextBootstrapWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_EndpointContext')]
    public function run(): string
    {
        return 'ready';
    }
}
