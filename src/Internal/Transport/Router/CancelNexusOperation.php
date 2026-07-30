<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Transport\Router;

use React\Promise\Deferred;
use Temporal\Api\Nexus\V1\CancelOperationRequest;
use Temporal\Api\Nexus\V1\Request;
use Temporal\DataConverter\EncodedValues;
use Temporal\Internal\Marshaller\MarshallerInterface;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Nexus\Handler\MethodCanceller;
use Temporal\Nexus\NexusOperationContext;
use Temporal\Worker\Environment\EnvironmentInterface;
use Temporal\Worker\Transport\Command\ServerRequestInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;

/**
 * Routes operation cancellation for compatibility transports and attaches
 * cooperative handler-method cancellation when an invocation ID is supplied.
 */
final class CancelNexusOperation extends Route
{
    public function __construct(
        private readonly NexusTaskHandler $taskHandler,
        private readonly MarshallerInterface $marshaller,
        private readonly ?EnvironmentInterface $env = null,
        private readonly ?RPCConnectionInterface $rpc = null,
    ) {}

    public function handle(ServerRequestInterface $request, array $headers, Deferred $resolver): void
    {
        try {
            $options = $request->getOptions();
            $operationContext = $this->marshaller->unmarshal($options, new NexusOperationContext());
            $methodCanceller = $this->createMethodCanceller($options);
            $protoRequest = self::buildProtoRequest($options);
            $this->taskHandler->handleCancelOperation(
                $protoRequest,
                $operationContext,
                $methodCanceller,
            );
            $resolver->resolve(EncodedValues::fromValues([]));
        } catch (\Throwable $e) {
            $resolver->reject($e);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function buildProtoRequest(array $options): Request
    {
        $cancelRequest = (new CancelOperationRequest())
            ->setService((string) ($options['service'] ?? ''))
            ->setOperation((string) ($options['operation'] ?? ''))
            ->setOperationToken((string) ($options['operationToken'] ?? ''));

        return (new Request())
            ->setHeader((array) ($options['headers'] ?? []))
            ->setEndpoint((string) ($options['endpoint'] ?? ''))
            ->setCancelOperation($cancelRequest);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createMethodCanceller(array $options): ?MethodCanceller
    {
        $invocationId = (int) ($options['invocationId'] ?? 0);
        if ($invocationId === 0 || $this->env === null || $this->rpc === null) {
            return null;
        }

        return new MethodCanceller(
            $this->env,
            NexusTaskHandler::deadlineFromHeaders((array) ($options['headers'] ?? [])),
            $this->rpc,
            $invocationId,
        );
    }
}
