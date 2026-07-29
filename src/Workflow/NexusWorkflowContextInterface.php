<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Workflow;

use React\Promise\PromiseInterface;
use Temporal\DataConverter\Type;
use Temporal\Internal\Workflow\NexusServiceProxy;
use Temporal\Workflow;

/**
 * Workflow context extension that supports Nexus outbound operations.
 *
 * Kept separate from {@see WorkflowContextInterface} so third-party context
 * implementations remain binary compatible when Nexus support is added.
 */
interface NexusWorkflowContextInterface extends WorkflowContextInterface
{
    /**
     * Returns a typed proxy for a Nexus service interface.
     * Method calls on the returned object will execute Nexus operations.
     *
     * @see Workflow::newNexusServiceStub()
     *
     * @template T of object
     * @param class-string<T> $class Nexus service interface annotated with #[Service]
     *
     * @return NexusServiceProxy<T>
     */
    public function newNexusServiceStub(
        string $class,
        NexusOperationOptions $options,
    ): object;

    /**
     * Returns an untyped Nexus operation stub.
     *
     * @see Workflow::newUntypedNexusOperationStub()
     */
    public function newUntypedNexusOperationStub(
        NexusOperationOptions $options,
    ): NexusOperationStubInterface;

    /**
     * Execute a Nexus operation directly without a typed stub.
     *
     * @see Workflow::executeNexusOperation()
     *
     * @param array<string, string> $nexusHeaders Raw-string headers carried on
     *        the Nexus wire and surfaced to the handler via OperationContext.
     */
    public function executeNexusOperation(
        string $operation,
        array $args = [],
        ?NexusOperationOptions $options = null,
        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
        array $nexusHeaders = [],
    ): PromiseInterface;
}
