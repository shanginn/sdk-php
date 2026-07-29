<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus;

use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker as WorkerTarget;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\OperatorServiceClient;

final class NexusEndpoints
{
    /** @var list<NexusEndpoint> */
    private array $registered = [];

    public function __construct(
        private readonly OperatorServiceClient $operator,
    ) {}

    public function register(
        string $namespace,
        string $taskQueue,
        string $prefix = 'test-nexus',
    ): NexusEndpoint {
        $name = $prefix . '-' . \bin2hex(\random_bytes(4));

        $request = (new CreateNexusEndpointRequest())
            ->setSpec(
                (new EndpointSpec())
                    ->setName($name)
                    ->setTarget(
                        (new EndpointTarget())->setWorker(
                            (new WorkerTarget())
                                ->setNamespace($namespace)
                                ->setTaskQueue($taskQueue),
                        ),
                    ),
            );

        [$response, $status] = $this->operator->CreateNexusEndpoint($request)->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new \RuntimeException(
                "CreateNexusEndpoint failed (gRPC code {$status->code}): {$status->details}",
            );
        }

        $endpoint = $response->getEndpoint();
        $registered = new NexusEndpoint(
            id: $endpoint->getId(),
            name: $name,
            version: $endpoint->getVersion(),
        );
        $this->registered[] = $registered;

        return $registered;
    }

    /**
     * Remove every endpoint created since the previous cleanup.
     */
    public function cleanup(): void
    {
        while ($endpoint = \array_pop($this->registered)) {
            $request = (new DeleteNexusEndpointRequest())
                ->setId($endpoint->id)
                ->setVersion($endpoint->version);

            [, $status] = $this->operator->DeleteNexusEndpoint($request)->wait();

            if ($status->code !== \Grpc\STATUS_OK && $status->code !== \Grpc\STATUS_NOT_FOUND) {
                $this->registered[] = $endpoint;
                throw new \RuntimeException(
                    "DeleteNexusEndpoint failed (gRPC code {$status->code}): {$status->details}",
                );
            }
        }
    }
}
