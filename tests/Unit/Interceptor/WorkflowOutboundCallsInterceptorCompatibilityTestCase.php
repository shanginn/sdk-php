<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Interceptor;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Temporal\Interceptor\NexusWorkflowOutboundCallsInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitInput;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitWithTimeoutInput;
use Temporal\Interceptor\WorkflowOutboundCalls\CancelExternalWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ContinueAsNewInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteChildWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteLocalActivityInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteNexusOperationInput;
use Temporal\Interceptor\WorkflowOutboundCalls\GetVersionInput;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCalls\SideEffectInput;
use Temporal\Interceptor\WorkflowOutboundCalls\SignalExternalWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\TimerInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertMemoInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertSearchAttributesInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertTypedSearchAttributesInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use Temporal\Workflow\NexusOperationOptions;

use function React\Promise\resolve;

final class WorkflowOutboundCallsInterceptorCompatibilityTestCase extends TestCase
{
    public function testLegacyThirdPartyInterceptorStillLoadsWithoutNexusMethod(): void
    {
        $interceptor = new LegacyThirdPartyWorkflowOutboundCallsInterceptor();

        self::assertInstanceOf(WorkflowOutboundCallsInterceptor::class, $interceptor);
        self::assertNotInstanceOf(NexusWorkflowOutboundCallsInterceptor::class, $interceptor);
        self::assertFalse(\method_exists($interceptor, 'executeNexusOperation'));
    }

    public function testNexusPipelineExcludesLegacyOutboundInterceptors(): void
    {
        $legacy = new LegacyThirdPartyWorkflowOutboundCallsInterceptor();
        $nexus = new class implements NexusWorkflowOutboundCallsInterceptor {
            use WorkflowOutboundCallsInterceptorTrait;

            public int $calls = 0;

            public function executeNexusOperation(
                ExecuteNexusOperationInput $input,
                callable $next,
            ): PromiseInterface {
                ++$this->calls;
                return $next($input);
            }
        };

        $pipeline = (new SimplePipelineProvider([$legacy, $nexus]))
            ->getPipeline(NexusWorkflowOutboundCallsInterceptor::class);
        $result = null;
        $pipeline->with(
            static fn(ExecuteNexusOperationInput $input): PromiseInterface => resolve($input->operation),
            'executeNexusOperation',
        )(new ExecuteNexusOperationInput(
            endpoint: 'endpoint',
            service: 'service',
            operation: 'operation',
            args: [],
            options: NexusOperationOptions::new(),
            returnType: null,
        ))->then(static function (string $value) use (&$result): void {
            $result = $value;
        });

        self::assertSame('operation', $result);
        self::assertSame(1, $nexus->calls);
        self::assertFalse($legacy->called);
    }
}

/**
 * Snapshot of an interceptor implementation compiled against the pre-Nexus
 * WorkflowOutboundCallsInterceptor.
 */
final class LegacyThirdPartyWorkflowOutboundCallsInterceptor implements WorkflowOutboundCallsInterceptor
{
    public bool $called = false;

    public function executeActivity(ExecuteActivityInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function executeLocalActivity(ExecuteLocalActivityInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function executeChildWorkflow(ExecuteChildWorkflowInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function signalExternalWorkflow(SignalExternalWorkflowInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function cancelExternalWorkflow(CancelExternalWorkflowInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function sideEffect(SideEffectInput $input, callable $next): mixed
    {
        $this->called = true;
        return $next($input);
    }

    public function timer(TimerInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function panic(PanicInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function complete(CompleteInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function continueAsNew(ContinueAsNewInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function getVersion(GetVersionInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function upsertMemo(UpsertMemoInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function upsertSearchAttributes(
        UpsertSearchAttributesInput $input,
        callable $next,
    ): PromiseInterface {
        $this->called = true;
        return $next($input);
    }

    public function upsertTypedSearchAttributes(
        UpsertTypedSearchAttributesInput $input,
        callable $next,
    ): PromiseInterface {
        $this->called = true;
        return $next($input);
    }

    public function await(AwaitInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }

    public function awaitWithTimeout(AwaitWithTimeoutInput $input, callable $next): PromiseInterface
    {
        $this->called = true;
        return $next($input);
    }
}
