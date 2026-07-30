<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Coresdk\Nexus\NexusTaskCompletion;
use Temporal\Api\Failure\V1\CanceledFailureInfo;
use Temporal\Api\Nexus\V1\Request;
use Temporal\Api\Nexus\V1\Response;
use Temporal\Api\Nexus\V1\StartOperationResponse;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Exception\Failure\FailureConverter;
use Temporal\Internal\Nexus\NexusTaskHandler;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\OperationException;
use Temporal\Nexus\Handler\MethodCanceller;
use Temporal\Nexus\NexusOperationContext;
use Temporal\Nexus\OperationState;

/**
 * Converts between Core Nexus completions and the SDK's public handler API.
 *
 * @internal
 */
final class NexusTaskTranslator
{
    public function __construct(
        private readonly DataConverterInterface $dataConverter,
    ) {}

    public function invoke(
        NexusTaskHandler $handler,
        Request $request,
        NexusOperationContext $context,
        MethodCanceller $canceller,
        ?\DateTimeImmutable $requestDeadline = null,
    ): Response {
        return match ($request->getVariant()) {
            'start_operation' => $handler->handleStartOperation(
                $request,
                $context,
                $canceller,
                $requestDeadline,
            ),
            'cancel_operation' => $handler->handleCancelOperation(
                $request,
                $context,
                $canceller,
                $requestDeadline,
            ),
            default => throw HandlerException::create(
                ErrorType::BadRequest,
                'Nexus request does not contain a supported operation variant.',
            ),
        };
    }

    public function completed(string $taskToken, Response $response): NexusTaskCompletion
    {
        return (new NexusTaskCompletion())
            ->setTaskToken($taskToken)
            ->setCompleted($response);
    }

    public function handlerFailure(string $taskToken, HandlerException $error): NexusTaskCompletion
    {
        // INTERNAL and UNAVAILABLE failures commonly carry infrastructure
        // details. Preserve their wire classification and retry behavior while
        // keeping messages, traces, and cause chains on the handler side.
        if ($error->errorType === ErrorType::Internal || $error->errorType === ErrorType::Unavailable) {
            $error = HandlerException::create(
                $error->errorType,
                'Internal Nexus handler error',
                retryBehavior: $error->retryBehavior,
            );
        }

        return (new NexusTaskCompletion())
            ->setTaskToken($taskToken)
            ->setFailure(FailureConverter::mapExceptionToFailure($error, $this->dataConverter));
    }

    public function operationFailure(string $taskToken, OperationException $error): NexusTaskCompletion
    {
        $failure = FailureConverter::mapExceptionToFailure($error, $this->dataConverter);

        // Core determines the terminal Nexus state from the failure-info
        // oneof, not from an SDK-specific application failure type.
        if ($error->state === OperationState::Canceled) {
            $failure->setCanceledFailureInfo(new CanceledFailureInfo());
        }

        $response = (new Response())->setStartOperation(
            (new StartOperationResponse())->setFailure($failure),
        );

        return $this->completed($taskToken, $response);
    }

    public function acknowledgeCancellation(string $taskToken): NexusTaskCompletion
    {
        return (new NexusTaskCompletion())
            ->setTaskToken($taskToken)
            ->setAckCancel(true);
    }
}
