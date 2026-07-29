<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Client\GRPC;

use Carbon\CarbonInterval;
use Temporal\Api\Workflowservice\V1\GetSystemInfoRequest;
use Temporal\Client\Common\BackoffThrottler;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\Common\ServerCapabilities;
use Temporal\Client\GRPC\Connection\Connection;
use Temporal\Client\GRPC\Connection\ConnectionInterface;
use Temporal\Exception\Client\CanceledException;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\TimeoutException;
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Transport\NativeUnaryClient;
use TrueAsync\Temporal\Core\Connection as CoreConnection;

abstract class BaseClient implements ServiceClientInterface
{
    public const RETRYABLE_ERRORS = [
        StatusCode::RESOURCE_EXHAUSTED,
        StatusCode::UNAVAILABLE,
        StatusCode::UNKNOWN,
    ];

    /** @var array<non-empty-string, class-string> */
    private static array $responseClasses = [];

    /** @var null|\Closure(string $method, object $arg, ContextInterface $ctx): object */
    private ?\Closure $invokePipeline = null;

    private Connection $connection;
    private ContextInterface $context;
    private \Stringable|string $apiKey = '';

    /**
     * @private Use static factory methods instead.
     *
     * @see self::create()
     * @see self::createSSL()
     */
    final public function __construct(CoreConnection|Connection $connection)
    {
        $this->connection = $connection instanceof Connection
            ? $connection
            : new Connection($connection);
        $this->context = Context::default();
    }

    /**
     * @param non-empty-string $address Temporal service address in format `host:port`
     * @psalm-suppress UndefinedClass
     */
    public static function create(string $address): static
    {
        return new static(new CoreConnection($address));
    }

    /**
     * @param non-empty-string $address Temporal service address in format `host:port`
     * @param non-empty-string|null $crt Root certificates string or file in PEM format.
     *        If null provided, default gRPC root certificates are used.
     * @param non-empty-string|null $clientKey Client private key string or file in PEM format.
     * @param non-empty-string|null $clientPem Client certificate chain string or file in PEM format.
     * @param non-empty-string|null $overrideServerName
     *
     */
    public static function createSSL(
        string $address,
        ?string $crt = null,
        ?string $clientKey = null,
        ?string $clientPem = null,
        ?string $overrideServerName = null,
    ): static {
        $loadCert = static function (?string $cert): ?string {
            return match (true) {
                $cert === null, $cert === '' => null,
                \is_file($cert) => false === ($content = \file_get_contents($cert))
                    ? throw new \InvalidArgumentException("Failed to load certificate from file `$cert`.")
                    : $content,
                default => $cert,
            };
        };

        return new static(new CoreConnection(
            address: $address,
            tls: true,
            tlsServerRootCaCert: $loadCert($crt),
            tlsClientCert: $loadCert($clientPem),
            tlsClientPrivateKey: $loadCert($clientKey),
            tlsServerName: $overrideServerName,
        ));
    }

    public function getContext(): ContextInterface
    {
        return $this->context;
    }

    public function withContext(ContextInterface $context): static
    {
        $clone = clone $this;
        $clone->context = $context;
        return $clone;
    }

    /**
     * Set the authentication token for the service client.
     *
     * This is the equivalent of providing an "Authorization" header with "Bearer " + the given key.
     * This will overwrite any "Authorization" header that may be on the context before each request to the
     * Temporal service.
     * You may pass your own {@see \Stringable} implementation to be able to change the key dynamically.
     *
     * @link https://docs.temporal.io/cloud/api-keys
     */
    public function withAuthKey(\Stringable|string $key): static
    {
        $clone = clone $this;
        $clone->apiKey = $key;
        return $clone;
    }

    /**
     * Close the communication channel associated with this stub.
     */
    public function close(): void
    {
        $this->connection->disconnect();
    }

    /**
     * @param null|Pipeline<GrpcClientInterceptor, object> $pipeline
     */
    final public function withInterceptorPipeline(?Pipeline $pipeline): static
    {
        $clone = clone $this;
        /** @see GrpcClientInterceptor::interceptCall() */
        $callable = $pipeline?->with($clone->call(...), 'interceptCall');
        $clone->invokePipeline = $callable === null ? null : $callable(...);
        return $clone;
    }

    public function getServerCapabilities(): ?ServerCapabilities
    {
        if ($this->connection->capabilities !== null) {
            return $this->connection->capabilities;
        }

        try {
            $systemInfo = $this->getSystemInfo(new GetSystemInfoRequest());
            $capabilities = $systemInfo->getCapabilities();

            if ($capabilities === null) {
                return null;
            }

            return $this->connection->capabilities = new ServerCapabilities(
                signalAndQueryHeader: $capabilities->getSignalAndQueryHeader(),
                internalErrorDifferentiation: $capabilities->getInternalErrorDifferentiation(),
                activityFailureIncludeHeartbeat: $capabilities->getActivityFailureIncludeHeartbeat(),
                supportsSchedules: $capabilities->getSupportsSchedules(),
                encodedFailureAttributes: $capabilities->getEncodedFailureAttributes(),
                buildIdBasedVersioning: $capabilities->getBuildIdBasedVersioning(),
                upsertMemo: $capabilities->getUpsertMemo(),
                eagerWorkflowStart: $capabilities->getEagerWorkflowStart(),
                sdkMetadata: $capabilities->getSdkMetadata(),
                countGroupByExecutionStatus: $capabilities->getCountGroupByExecutionStatus(),
                nexus: $capabilities->getNexus(),
            );
        } catch (ServiceClientException $e) {
            if ($e->getCode() === StatusCode::UNIMPLEMENTED) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @deprecated
     */
    public function setServerCapabilities(ServerCapabilities $capabilities): void
    {
        \trigger_error(
            'Method ' . __METHOD__ . ' is deprecated and will be removed in the next major release.',
            \E_USER_DEPRECATED,
        );
    }

    /**
     * Note: Experimental
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @param non-empty-string $method RPC method name
     *
     * @throw ClientException
     */
    protected function invoke(string $method, object $arg, ?ContextInterface $ctx = null): mixed
    {
        $ctx ??= $this->getContext();

        // Add the API key to the context
        $key = (string) $this->apiKey;
        if ($key !== '') {
            $ctx = $ctx->withMetadata([
                'Authorization' => ["Bearer $key"],
            ] + $ctx->getMetadata());
        }

        return $this->invokePipeline !== null
            ? ($this->invokePipeline)($method, $arg, $ctx)
            : $this->call($method, $arg, $ctx);
    }

    /**
     * Perform a single wire call and return the decoded response message.
     *
     * The retry loop, deadline handling, interceptor pipeline and exception
     * mapping stay in PHP. A single attempt is delegated to the native
     * TrueAsync Temporal bridge, which parks this coroutine while the Rust core
     * performs the RPC.
     *
     * @param non-empty-string $method
     *
     * @throws ServiceClientException on a non-OK status.
     */
    protected function performCall(string $method, object $arg, ContextInterface $ctx, array $options): object
    {
        $responseClass = self::$responseClasses[$method] ??= self::resolveResponseClass($method);
        $timeoutMs = isset($options['timeout'])
            ? (int) \ceil(((int) $options['timeout']) / 1000)
            : 0;

        return (new NativeUnaryClient(
            $this->connection->getCore(),
            NativeUnaryClient::SERVICE_WORKFLOW,
        ))->call($method, $arg, $responseClass, $timeoutMs, $ctx->getMetadata());
    }

    /**
     * Resolve the response protobuf from the authoritative generated client
     * interface instead of relying on a method-name convention.
     *
     * @return class-string
     */
    private static function resolveResponseClass(string $method): string
    {
        $type = (new \ReflectionMethod(ServiceClientInterface::class, $method))->getReturnType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException("Cannot resolve a response message type for RPC {$method}.");
        }

        return $type->getName();
    }

    /**
     * Call a gRPC method.
     * Used in {@see withInterceptorPipeline()}
     *
     * @param non-empty-string $method
     *
     * @throws \Exception
     */
    private function call(string $method, object $arg, ContextInterface $ctx): object
    {
        $attempt = 0;
        $retryOption = RpcRetryOptions::fromRetryOptions($ctx->getRetryOptions());
        $initialIntervalMs = $congestionInitialIntervalMs = $throttler = null;

        do {
            ++$attempt;
            try {
                $options = $ctx->getOptions();
                $deadline = $ctx->getDeadline();
                if ($deadline !== null) {
                    $diff = (new \DateTime())->diff($deadline);
                    $options['timeout'] = CarbonInterval::instance($diff)->totalMicroseconds;
                }

                return $this->performCall($method, $arg, $ctx, $options);
            } catch (ServiceClientException $e) {
                if (!\in_array($e->getCode(), self::RETRYABLE_ERRORS, true)) {
                    if ($e->getCode() === StatusCode::DEADLINE_EXCEEDED) {
                        throw new TimeoutException($e->getMessage(), $e->getCode(), $e);
                    }

                    if ($e->getCode() === StatusCode::CANCELLED) {
                        throw new CanceledException($e->getMessage(), $e->getCode(), $e);
                    }

                    // non retryable
                    throw $e;
                }

                if ($retryOption->maximumAttempts !== 0 && $attempt >= $retryOption->maximumAttempts) {
                    // Reached maximum attempts
                    throw $e;
                }

                if ($ctx->getDeadline() !== null && new \DateTimeImmutable() > $ctx->getDeadline()) {
                    // Deadline is reached
                    throw new TimeoutException('Call timeout has been reached');
                }

                // Init interval values in milliseconds
                $initialIntervalMs ??= $retryOption->initialInterval === null
                    ? (int) CarbonInterval::millisecond(50)->totalMilliseconds
                    : (int) (new CarbonInterval($retryOption->initialInterval))->totalMilliseconds;
                $congestionInitialIntervalMs ??= $retryOption->congestionInitialInterval === null
                    ? (int) CarbonInterval::millisecond(1000)->totalMilliseconds
                    : (int) (new CarbonInterval($retryOption->congestionInitialInterval))->totalMilliseconds;

                $throttler ??= new BackoffThrottler(
                    maxInterval: $retryOption->maximumInterval !== null
                        ? (int) (new CarbonInterval($retryOption->maximumInterval))->totalMilliseconds
                        : $initialIntervalMs * 200,
                    maxJitterCoefficient: $retryOption->maximumJitterCoefficient,
                    backoffCoefficient: $retryOption->backoffCoefficient,
                );

                // Initial interval always depends on the *most recent* failure.
                $baseInterval = $e->getCode() === StatusCode::RESOURCE_EXHAUSTED
                    ? $congestionInitialIntervalMs
                    : $initialIntervalMs;

                $wait = $throttler->calculateSleepTime(failureCount: $attempt, initialInterval: $baseInterval) * 1000;

                // wait till the next call
                $this->usleep($wait);
            }
        } while (true);
    }

    /**
     * @param int<0, max> $param Delay in microseconds
     */
    private function usleep(int $param): void
    {
        // TrueAsync: yield this coroutine instead of blocking the reactor for the
        // whole backoff (and stay cancellation-aware). Coroutines aren't \Fiber.
        if (\function_exists('Async\\delay')) {
            \Async\delay(\intdiv($param, 1000));
            return;
        }

        if (\Fiber::getCurrent() === null) {
            \usleep($param);
            return;
        }

        $deadline = \microtime(true) + (float) ($param / 1_000_000);

        while (\microtime(true) < $deadline) {
            \Fiber::suspend();
        }
    }
}
