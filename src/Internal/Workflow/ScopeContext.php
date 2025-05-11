<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Workflow;

use Amp\Future;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Internal\Transport\CompletableResult;
use Temporal\Internal\Workflow\Process\Fiber\FiberScope;
use Temporal\Internal\Workflow\Process\Scope;
use Temporal\Promise;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow\CancellationScopeInterface;
use Temporal\Workflow\ScopedContextInterface;
use Temporal\Workflow\UpdateContext;

class ScopeContext extends WorkflowContext implements ScopedContextInterface
{
    private WorkflowContext $parent;
    private $scope;
    private \Closure $onRequest;
    private ?UpdateContext $updateContext = null;

    /**
     * Creates scope specific context.
     * 
     * @param WorkflowContext $context
     * @param Scope|FiberScope $scope
     * @param \Closure $onRequest
     * @param UpdateContext|null $updateContext
     * @return self
     */
    public static function fromWorkflowContext(
        WorkflowContext $context,
        $scope,
        \Closure $onRequest,
        ?UpdateContext $updateContext,
    ): self {
        $ctx = new self(
            $context->services,
            $context->client,
            $context->workflowInstance,
            $context->input,
            $context->getLastCompletionResultValues(),
            $context->handlers,
        );

        $ctx->parent = $context;
        $ctx->scope = $scope;
        $ctx->onRequest = $onRequest;
        $ctx->updateContext = $updateContext;

        return $ctx;
    }

    public function async(callable $handler): CancellationScopeInterface
    {
        return $this->scope->startScope($handler, false);
    }

    public function asyncDetached(callable $handler): CancellationScopeInterface
    {
        return $this->scope->startScope($handler, true);
    }

    #[\Override]
    public function request(
        RequestInterface $request,
        bool $cancellable = true,
        bool $waitResponse = true,
    ): Promise {
        $cancellable && $this->scope->isCancelled() && throw new CanceledFailure(
            'Attempt to send request to cancelled scope',
        );

        if (!$waitResponse) {
            return $this->parent->request($request, $cancellable, false);
        }

        $promise = $this->parent->request($request);
        ($this->onRequest)($request, $promise);

        return new CompletableResult(
            $this,
            $this->services->loop,
            $promise,
            $this->scope->getLayer(),
        );
    }

    public function getUpdateContext(): ?UpdateContext
    {
        return $this->updateContext;
    }

    public function resolveConditions(): void
    {
        $this->parent->resolveConditions();
    }
}