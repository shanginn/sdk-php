<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Nexus\Exception;

/**
 * Thrown from a handler.
 *
 * For caller-visible error types, the message and complete cause chain,
 * including details and stack traces, can be serialized into the Nexus
 * failure. Pass only caller-safe messages and Throwables. Unexpected raw
 * exceptions and explicit Internal or Unavailable handler failures are
 * redacted at the native handler boundary.
 */
final class HandlerException extends NexusException
{
    public readonly ErrorType $errorType;
    public readonly RetryBehavior $retryBehavior;

    private function __construct(
        ErrorType $errorType,
        string $message,
        ?\Throwable $cause,
        RetryBehavior $retryBehavior,
    ) {
        $this->errorType = $errorType;
        $this->retryBehavior = $retryBehavior;

        parent::__construct($message, 0, $cause);
    }

    public static function create(
        ErrorType $errorType,
        string $message,
        ?\Throwable $cause = null,
        RetryBehavior $retryBehavior = RetryBehavior::Unspecified,
    ): self {
        return new self($errorType, $message, $cause, $retryBehavior);
    }

    /**
     * Message is derived from the cause's own message.
     *
     * The cause itself can also be serialized for caller-visible error types.
     * Internal and Unavailable failures are redacted at the native boundary,
     * but handler code should still avoid putting secrets in exceptions.
     */
    public static function fromCause(
        ErrorType $errorType,
        \Throwable $cause,
        RetryBehavior $retryBehavior = RetryBehavior::Unspecified,
    ): self {
        $message = $cause->getMessage() !== ''
            ? "handler error: {$cause->getMessage()}"
            : 'handler error';
        return new self($errorType, $message, $cause, $retryBehavior);
    }

    public function isRetryable(): bool
    {
        if ($this->retryBehavior !== RetryBehavior::Unspecified) {
            return $this->retryBehavior === RetryBehavior::Retryable;
        }

        return match ($this->errorType) {
            ErrorType::BadRequest,
            ErrorType::Unauthenticated,
            ErrorType::Unauthorized,
            ErrorType::NotFound,
            ErrorType::Conflict,
            ErrorType::NotImplemented => false,
            default => true,
        };
    }
}
