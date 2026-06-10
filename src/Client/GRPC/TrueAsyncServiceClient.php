<?php

declare(strict_types=1);

namespace Temporal\Client\GRPC;

use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;
use Temporal\Exception\Client\ServiceClientException;
use TrueAsync\Temporal\ConnectionException as CoreConnectionException;
use TrueAsync\Temporal\Core\Connection as CoreConnection;
use TrueAsync\Temporal\ServiceException as CoreServiceException;

/**
 * A {@see ServiceClientInterface} backed by the TrueAsync Rust core transport
 * instead of gRPC/RoadRunner.
 *
 * It reuses all of {@see ServiceClient}: the RPC method declarations, the
 * context/interceptor surface and the exception types. Only {@see invoke()} is
 * overridden — each call is routed through the native
 * {@see CoreConnection::rpcCall()}, which serialises the request, parks the
 * current coroutine while the Rust core drives the gRPC on its own threads, and
 * resumes with the response bytes. Retries are handled by the core, so the
 * gRPC retry loop in {@see BaseClient} is bypassed.
 */
final class TrueAsyncServiceClient extends ServiceClient
{
    /** WorkflowService selector for the core (mirrors TemporalCoreRpcService). */
    private const SERVICE_WORKFLOW = 1;

    private CoreConnection $core;

    public static function fromCore(CoreConnection $core): self
    {
        // The gRPC client factory is never invoked: invoke() never reaches the
        // gRPC path, and the connection wrapper is lazy.
        $self = new self(static fn(): WorkflowServiceClient => throw new \LogicException(
            'gRPC transport is disabled in the TrueAsync service client',
        ));
        $self->core = $core;

        return $self;
    }

    /**
     * The single transport seam (overrides {@see BaseClient::performCall()}), so
     * invoke()'s interceptor pipeline, the API-key handling and call()'s retry
     * loop are all reused — only the wire transport is swapped for the core.
     */
    protected function performCall(string $method, object $arg, ContextInterface $ctx, array $options): object
    {
        // The WorkflowService follows the XxxRequest -> XxxResponse convention.
        $reqClass = $arg::class;
        if (!\str_ends_with($reqClass, 'Request')) {
            throw new \LogicException("Unexpected request message type: {$reqClass}");
        }
        $respClass = \substr($reqClass, 0, -\strlen('Request')) . 'Response';

        // call() already folded the per-attempt deadline into $options['timeout']
        // (microseconds). The core does no retrying — call()'s loop owns that.
        $timeoutMs = isset($options['timeout']) ? (int) ((int) $options['timeout'] / 1000) : 0;

        try {
            $responseBytes = $this->core->rpcCall(
                self::SERVICE_WORKFLOW,
                $method,
                $arg->serializeToString(),
                $timeoutMs,
            );
        } catch (CoreServiceException $e) {
            throw self::mapException($e, (int) $e->getCode());
        } catch (CoreConnectionException $e) {
            throw self::mapException($e, StatusCode::UNAVAILABLE);
        }

        /** @var \Google\Protobuf\Internal\Message $response */
        $response = new $respClass();
        $response->mergeFromString($responseBytes);

        return $response;
    }

    private static function mapException(\Throwable $e, int $statusCode): ServiceClientException
    {
        $status = new \stdClass();
        $status->code = $statusCode;
        $status->details = $e->getMessage();
        $status->metadata = [];

        // Forward the serialized google.rpc.Status so the SDK can map specific
        // errors (e.g. WorkflowExecutionAlreadyStarted) from the status details.
        if ($e instanceof CoreServiceException && $e->statusDetails !== null) {
            $status->metadata['grpc-status-details-bin'] = [$e->statusDetails];
        }

        return new ServiceClientException($status, $e);
    }
}
