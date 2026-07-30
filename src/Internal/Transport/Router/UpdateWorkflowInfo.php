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
use Temporal\DataConverter\EncodedValues;
use Temporal\Worker\Transport\Command\ServerRequestInterface;

/**
 * Applies activation metadata when Core emits no job that otherwise enters the
 * Workflow runtime (for example, a patch-only activation).
 */
final class UpdateWorkflowInfo extends WorkflowProcessAwareRoute
{
    public function handle(ServerRequestInterface $request, array $headers, Deferred $resolver): void
    {
        $runId = $request->getID();
        if ($runId === '') {
            throw new \InvalidArgumentException('Workflow run identifier must not be empty.');
        }

        $process = $this->findProcessOrFail($runId);
        $request->getTickInfo()->applyTo($process->getContext()->getInfo());

        $resolver->resolve(EncodedValues::fromValues([null]));
    }
}
