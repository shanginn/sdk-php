<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Nexus\Attribute;

use Doctrine\Common\Annotations\Annotation\Target;
use Spiral\Attributes\NamedArgumentConstructor;
use Temporal\Nexus\Handler\OperationHandlerInterface;
use Temporal\Nexus\WorkflowHandle;

/**
 * Marks a method on a {@see Service}-annotated type (interface or class) as a Nexus operation.
 *
 * Nexus service contracts do not declare whether an operation completes
 * synchronously or asynchronously. A regular method is a synchronous
 * convenience handler. A method returning {@see WorkflowHandle} is backed by
 * a Workflow run, and a zero-argument method returning an
 * {@see OperationHandlerInterface} may choose a synchronous or asynchronous
 * {@see \Temporal\Nexus\Handler\OperationStartResult} for each invocation.
 *
 * The PHP method signature normally defines the input and output types.
 * Handler factories and Workflow-run handlers declare their wire types via
 * {@see self::$input} and {@see self::$output}.
 *
 * @Annotation
 * @NamedArgumentConstructor
 * @Target({ "METHOD" })
 */
#[\Attribute(\Attribute::TARGET_METHOD), NamedArgumentConstructor]
final class Operation
{
    /**
     * @param string $name Operation name as exposed over the wire. Empty means "use the method name".
     * @param string $output Wire output type for a Workflow-run operation or handler factory.
     *        Empty means "void".
     * @param string $input Wire input type for a handler factory, whose zero-parameter signature
     *        cannot carry it. Empty means "mixed".
     */
    public function __construct(
        public readonly string $name = '',
        public readonly string $output = '',
        public readonly string $input = '',
    ) {}
}
