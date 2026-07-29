<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Harness\EagerWorkflow\SuccessfulStart;

use PHPUnit\Framework\Attributes\Test;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\TrueAsyncServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Tests\Acceptance\App\Runtime\State;
use Temporal\Tests\Acceptance\App\TestCase;
use Temporal\WorkerFactory;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use TrueAsync\Temporal\Core\Connection;

\define('EXPECTED_RESULT', 'Hello World');

class SuccessfulStartTest extends TestCase
{
    private grpcCallInterceptor $interceptor;

    public function pipelineProvider(): PipelineProvider
    {
        return new SimplePipelineProvider([$this->interceptor]);
    }

    #[Test]
    public function start(
        State $state,
    ): void {
        $taskQueue = 'eager-' . \bin2hex(\random_bytes(8));
        $connection = new Connection($state->address);
        $factory = WorkerFactory::create(
            connection: $connection,
            namespace: $state->namespace,
        );
        $factory->newWorker($taskQueue)->registerWorkflowTypes(FeatureWorkflow::class);
        $worker = \Async\spawn($factory->run(...));

        try {
            // Eager dispatch is connection-local in Temporal Core: the client
            // must issue StartWorkflowExecution through the same connection
            // that owns the worker's reserved workflow-task slot.
            $pipelineProvider = $this->pipelineProvider();
            $serviceClient = TrueAsyncServiceClient::fromCore($connection)
                ->withInterceptorPipeline($pipelineProvider->getPipeline(GrpcClientInterceptor::class));
            $client = WorkflowClient::create(
                serviceClient: $serviceClient,
                options: (new ClientOptions())->withNamespace($state->namespace),
                interceptorProvider: $pipelineProvider,
            );
            $stub = $client->newUntypedWorkflowStub(
                'Harness_EagerWorkflow_SuccessfulStart',
                WorkflowOptions::new()
                    ->withTaskQueue($taskQueue)
                    ->withWorkflowId('eager-' . \bin2hex(\random_bytes(8)))
                    ->withEagerStart(),
            );

            $run = $client->start($stub);

            self::assertSame(EXPECTED_RESULT, $run->getResult());
            self::assertNotNull($this->interceptor->lastResponse);
            self::assertNotNull($this->interceptor->lastResponse->getEagerWorkflowTask());
        } finally {
            $factory->shutdown();
            \Async\await($worker);
        }
    }

    protected function setUp(): void
    {
        $this->interceptor = new grpcCallInterceptor();
        parent::setUp();
    }
}

#[WorkflowInterface]
class FeatureWorkflow
{
    #[WorkflowMethod('Harness_EagerWorkflow_SuccessfulStart')]
    public function run()
    {
        return EXPECTED_RESULT;
    }
}

/**
 * Catches {@see StartWorkflowExecutionResponse} from the gRPC calls.
 */
class grpcCallInterceptor implements GrpcClientInterceptor
{
    public ?StartWorkflowExecutionResponse $lastResponse = null;

    public function interceptCall(string $method, object $arg, ContextInterface $ctx, callable $next): object
    {
        $result = $next($method, $arg, $ctx);
        $result instanceof StartWorkflowExecutionResponse and $this->lastResponse = $result;
        return $result;
    }
}
