<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Nexus;

use Google\Rpc\Code;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowException;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\RetryBehavior;

/**
 * @internal
 */
final class HandlerErrorMapper
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    public static function mapToHandlerException(\Throwable $e): ?HandlerException
    {
        if ($e instanceof ApplicationFailure && $e->isNonRetryable()) {
            return self::safe(ErrorType::Internal, RetryBehavior::NonRetryable);
        }

        if ($e instanceof WorkflowNotFoundException) {
            return self::safe(ErrorType::NotFound);
        }

        if ($e instanceof WorkflowExecutionAlreadyStartedException) {
            return self::safe(ErrorType::Internal, RetryBehavior::NonRetryable);
        }

        if ($e instanceof WorkflowException) {
            $previous = $e->getPrevious();
            if ($previous instanceof ServiceClientException) {
                return self::fromGrpcCode($previous);
            }
        }

        if ($e instanceof ServiceClientException) {
            return self::fromGrpcCode($e);
        }

        return null;
    }

    private static function fromGrpcCode(ServiceClientException $e): HandlerException
    {
        return match ($e->getCode()) {
            Code::INVALID_ARGUMENT => self::safe(ErrorType::BadRequest),
            Code::ALREADY_EXISTS,
            Code::FAILED_PRECONDITION,
            Code::OUT_OF_RANGE => self::safe(ErrorType::Internal, RetryBehavior::NonRetryable),
            Code::ABORTED,
            Code::UNAVAILABLE => self::safe(ErrorType::Unavailable),
            // Unauthenticated/PermissionDenied collapse to Internal: a handler-side auth failure against Temporal, not a Nexus-caller auth error.
            Code::CANCELLED,
            Code::DATA_LOSS,
            Code::INTERNAL,
            Code::UNKNOWN,
            Code::UNAUTHENTICATED,
            Code::PERMISSION_DENIED => self::safe(ErrorType::Internal),
            Code::NOT_FOUND => self::safe(ErrorType::NotFound),
            Code::RESOURCE_EXHAUSTED => self::safe(ErrorType::ResourceExhausted),
            Code::UNIMPLEMENTED => self::safe(ErrorType::NotImplemented),
            Code::DEADLINE_EXCEEDED => self::safe(ErrorType::UpstreamTimeout),
            default => self::safe(ErrorType::Internal),
        };
    }

    private static function safe(
        ErrorType $errorType,
        RetryBehavior $retryBehavior = RetryBehavior::Unspecified,
    ): HandlerException {
        $message = match ($errorType) {
            ErrorType::BadRequest => 'Nexus handler dependency rejected the request',
            ErrorType::NotFound => 'Nexus handler dependency was not found',
            ErrorType::ResourceExhausted => 'Nexus handler dependency is resource exhausted',
            ErrorType::NotImplemented => 'Nexus handler dependency does not support this operation',
            ErrorType::Unavailable => 'Nexus handler dependency is unavailable',
            ErrorType::UpstreamTimeout => 'Nexus handler dependency timed out',
            default => 'Internal Nexus handler error',
        };

        return HandlerException::create($errorType, $message, retryBehavior: $retryBehavior);
    }
}
