<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus\Headers;

use Temporal\Nexus\Attribute\Operation;
use Temporal\Nexus\Attribute\Service;
use Temporal\Nexus\Header;
use Temporal\Nexus\Nexus;
use PHPUnit\Framework\Attributes\Test;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Tests\Acceptance\App\Attribute\Stub;
use Temporal\Tests\Acceptance\App\Attribute\Worker;
use Temporal\Tests\Acceptance\App\Runtime\State;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusEndpoints;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusHttpClient;
use Temporal\Tests\Acceptance\Extra\Nexus\NexusWorkerOptions;
use Temporal\Worker\WorkerOptions;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/**
 * Acceptance test: HTTP request headers are propagated to the Nexus handler.
 */
#[Worker(options: [self::class, 'workerOptions'])]
class HeadersTest extends TestCase
{
    public static function workerOptions(): WorkerOptions
    {
        return NexusWorkerOptions::default();
    }

    #[Test]
    public function customHeadersArePropagated(
        State $state,
        NexusEndpoints $endpoints,
        NexusHttpClient $http,
        #[Stub('Extra_Nexus_Headers_Bootstrap')]
        WorkflowStubInterface $stub,
    ): void {
        $stub->getResult('string');

        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'test-nexus-hdr');

        [$code, $resp, ] = $http->post(
            $endpoint,
            'HeaderEchoService',
            'echoHeader',
            'X-Custom-Header',
            ['X-Custom-Header' => 'my-test-value'],
        );

        self::assertSame(200, $code, "Expected 200, got {$code}. Response: {$resp}");
        self::assertStringContainsString('my-test-value', $resp, 'Expected header value in response');
    }

    #[Test]
    public function timeoutHeadersUsePinnedNexusWireGrammar(
        State $state,
        NexusEndpoints $endpoints,
        NexusHttpClient $http,
        #[Stub('Extra_Nexus_Headers_Bootstrap')]
        WorkflowStubInterface $stub,
    ): void {
        $stub->getResult('string');

        $endpoint = $endpoints->register($state->namespace, __NAMESPACE__, 'test-nexus-timeout-hdr');

        [$code, $resp, ] = $http->post(
            $endpoint,
            'HeaderEchoService',
            'echoTimeoutHeaders',
            'ignored',
            [
                Header::REQUEST_TIMEOUT => '5s',
                Header::OPERATION_TIMEOUT => '2m',
            ],
        );

        self::assertSame(200, $code, "Expected 200, got {$code}. Response: {$resp}");
        self::assertMatchesRegularExpression(
            '/request=\d+(?:\.\d+)?(?:ms|s|m);operation=\d+(?:\.\d+)?(?:ms|s|m)/',
            $resp,
            'Core may normalize timeout units, but both fields must retain the pinned Nexus duration grammar.',
        );
    }
}

#[Service(name: 'HeaderEchoService')]
class HeaderEchoService
{
    #[Operation]
    public function echoHeader(string $headerName): string
    {
        // Headers in OperationContext are case-insensitive (lowercased)
        $context = Nexus::getCurrentOperationContext();
        return $context->headers->get($headerName) ?? "missing:{$headerName}";
    }

    #[Operation]
    public function echoTimeoutHeaders(string $ignored): string
    {
        $headers = Nexus::getCurrentOperationContext()->headers;
        return \sprintf(
            'request=%s;operation=%s',
            $headers->get(Header::REQUEST_TIMEOUT) ?? 'missing',
            $headers->get(Header::OPERATION_TIMEOUT) ?? 'missing',
        );
    }
}

#[WorkflowInterface]
class HeadersBootstrapWorkflow
{
    #[WorkflowMethod(name: 'Extra_Nexus_Headers_Bootstrap')]
    public function run(): string
    {
        return 'ready';
    }
}
