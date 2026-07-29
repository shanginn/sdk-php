<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Nexus\Internal;

use Temporal\Client\WorkflowClientInterface;
use Temporal\Nexus\Exception\ErrorType;
use Temporal\Nexus\Exception\HandlerException;
use Temporal\Nexus\Exception\RetryBehavior;

/**
 * @internal
 */
final class WorkflowClientNamespace
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    public static function assertMatches(
        WorkflowClientInterface $client,
        string ...$expectedNamespaces,
    ): void {
        try {
            $metadata = $client->getServiceClient()->getContext()->getMetadata();
        } catch (\Throwable) {
            throw self::configurationError(
                'Unable to determine the WorkflowClient namespace for a workflow-backed Nexus operation.',
            );
        }

        $namespaceValues = [];
        foreach ($metadata as $name => $values) {
            if (!\is_string($name) || \strtolower($name) !== 'temporal-namespace') {
                continue;
            }

            foreach (\is_array($values) ? $values : [$values] as $value) {
                $namespaceValues[] = $value;
            }
        }

        if (
            \count($namespaceValues) !== 1
            || !\is_string($namespaceValues[0])
            || $namespaceValues[0] === ''
        ) {
            throw self::configurationError(
                'WorkflowClient metadata must contain exactly one non-empty Temporal-Namespace value '
                . 'for a workflow-backed Nexus operation.',
            );
        }

        foreach ($expectedNamespaces as $expectedNamespace) {
            if ($expectedNamespace === '' || $namespaceValues[0] !== $expectedNamespace) {
                throw self::configurationError(
                    'The WorkflowClient namespace must match the Nexus operation and operation-token namespaces.',
                );
            }
        }
    }

    private static function configurationError(string $message): HandlerException
    {
        return HandlerException::create(
            ErrorType::Internal,
            $message,
            retryBehavior: RetryBehavior::NonRetryable,
        );
    }
}
