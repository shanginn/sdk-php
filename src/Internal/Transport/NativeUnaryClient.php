<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Transport;

use Google\Protobuf\Internal\Message;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Exception\Client\ServiceClientException;
use TrueAsync\Temporal\ConnectionException as CoreConnectionException;
use TrueAsync\Temporal\Core\Connection as CoreConnection;
use TrueAsync\Temporal\ServiceException as CoreServiceException;

/**
 * Typed unary RPC adapter shared by the public and testing clients.
 *
 * @internal
 */
final class NativeUnaryClient
{
    public const SERVICE_WORKFLOW = 1;
    public const SERVICE_OPERATOR = 2;
    public const SERVICE_CLOUD = 3;
    public const SERVICE_TEST = 4;
    public const SERVICE_HEALTH = 5;

    public function __construct(
        private readonly CoreConnection $connection,
        private readonly int $service,
    ) {
        if ($service < self::SERVICE_WORKFLOW || $service > self::SERVICE_HEALTH) {
            throw new \InvalidArgumentException("Unknown Temporal core service selector {$service}.");
        }
    }

    /**
     * @template TResponse of Message
     *
     * @param class-string<TResponse> $responseClass
     * @param int<0, max> $timeoutMs
     * @param array<string, string|list<string>> $metadata
     *
     * @return TResponse
     */
    public function call(
        string $method,
        object $request,
        string $responseClass,
        int $timeoutMs = 0,
        array $metadata = [],
    ): Message {
        if (!\method_exists($request, 'serializeToString')) {
            throw new \InvalidArgumentException(\sprintf(
                'Temporal RPC request must be a protobuf message, %s given.',
                \get_debug_type($request),
            ));
        }
        if (!\is_a($responseClass, Message::class, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Temporal RPC response class must extend %s, %s given.',
                Message::class,
                $responseClass,
            ));
        }

        try {
            $bytes = $this->connection->rpcCall(
                $this->service,
                $method,
                $request->serializeToString(),
                $timeoutMs,
                $metadata,
            );
        } catch (CoreServiceException $error) {
            throw self::mapException($error, (int) $error->getCode());
        } catch (CoreConnectionException $error) {
            throw self::mapException($error, StatusCode::UNAVAILABLE);
        }

        /** @var TResponse $response */
        $response = new $responseClass();
        $response->mergeFromString($bytes);

        return $response;
    }

    private static function mapException(\Throwable $error, int $statusCode): ServiceClientException
    {
        $status = new \stdClass();
        $status->code = $statusCode;
        $status->details = $error->getMessage();
        $status->metadata = [];

        if ($error instanceof CoreServiceException && $error->statusDetails !== null) {
            $status->metadata['grpc-status-details-bin'] = [$error->statusDetails];
        }

        return new ServiceClientException($status, $error);
    }
}
