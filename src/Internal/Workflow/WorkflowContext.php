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
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Temporal\Activity\ActivityOptions;
use Temporal\Activity\ActivityOptionsInterface;
use Temporal\Activity\LocalActivityOptions;
use Temporal\Common\SearchAttributes\SearchAttributeKey;
use Temporal\Common\SearchAttributes\SearchAttributeUpdate;
use Temporal\Common\Uuid;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\Type;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Interceptor\HeaderInterface;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitInput;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitWithTimeoutInput;
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ContinueAsNewInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteChildWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteLocalActivityInput;
use Temporal\Interceptor\WorkflowOutboundCalls\GetVersionInput;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCalls\SideEffectInput;
use Temporal\Interceptor\WorkflowOutboundCalls\TimerInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertMemoInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertSearchAttributesInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertTypedSearchAttributesInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use Temporal\Interceptor\WorkflowOutboundRequestInterceptor;
use Temporal\Internal\Declaration\Destroyable;
use Temporal\Internal\Declaration\WorkflowInstanceInterface;
use Temporal\Internal\Interceptor\HeaderCarrier;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Promise\DeferredPromise;
use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Support\DateInterval;
use Temporal\Internal\Support\StackRenderer;
use Temporal\Internal\Transport\ClientInterface;
use Temporal\Internal\Transport\CompletableResultInterface;
use Temporal\Internal\Transport\Request\Cancel;
use Temporal\Internal\Transport\Request\CompleteWorkflow;
use Temporal\Internal\Transport\Request\ContinueAsNew;
use Temporal\Internal\Transport\Request\GetVersion;
use Temporal\Internal\Transport\Request\NewTimer;
use Temporal\Internal\Transport\Request\Panic;
use Temporal\Internal\Transport\Request\SideEffect;
use Temporal\Internal\Transport\Request\UpsertMemo;
use Temporal\Internal\Transport\Request\UpsertSearchAttributes;
use Temporal\Internal\Transport\Request\UpsertTypedSearchAttributes;
use Temporal\Internal\Workflow\Process\HandlerState;
use Temporal\Promise;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow\ActivityStubInterface;
use Temporal\Workflow\ChildWorkflowOptions;
use Temporal\Workflow\ChildWorkflowStubInterface;
use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ExternalWorkflowStubInterface;
use Temporal\Workflow\Mutex;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowInfo;

/**
 * @internal
 */
class WorkflowContext implements WorkflowContextInterface, HeaderCarrier, Destroyable
{
    /**
     * Contains conditional groups that contains tuple of a condition callable and its promise
     * @var array<non-empty-string, array<int<0, max>, array{callable, DeferredPromise}>>
     */
    protected array $awaits = [];

    private array $trace = [];
    private bool $continueAsNew = false;

    /** @var Pipeline<WorkflowOutboundRequestInterceptor, Promise> */
    private Pipeline $requestInterceptor;

    /** @var Pipeline<WorkflowOutboundCallsInterceptor, Promise> */
    private Pipeline $callsInterceptor;

    /**
     * @param HandlerState $handlers Counter of active Update and Signal handlers
     */
    public function __construct(
        protected ServiceContainer $services,
        protected ClientInterface $client,
        protected WorkflowInstanceInterface&Destroyable $workflowInstance,
        protected Input $input,
        protected ?ValuesInterface $lastCompletionResult = null,
        protected HandlerState $handlers = new HandlerState(),
    ) {
        $this->requestInterceptor =  $services->interceptorProvider
            ->getPipeline(WorkflowOutboundRequestInterceptor::class);
        $this->callsInterceptor =  $services->interceptorProvider
            ->getPipeline(WorkflowOutboundCallsInterceptor::class);
    }

    public function getWorkflowInstance(): WorkflowInstanceInterface
    {
        return $this->workflowInstance;
    }

    public function now(): \DateTimeInterface
    {
        return $this->services->env->now();
    }

    public function getRunId(): string
    {
        return $this->input->info->execution->getRunID();
    }

    public function getInfo(): WorkflowInfo
    {
        return $this->input->info;
    }

    public function getHeader(): HeaderInterface
    {
        return $this->input->header;
    }

    public function getInput(): ValuesInterface
    {
        return $this->input->input;
    }

    public function withInput(Input $input): static
    {
        $clone = clone $this;
        $clone->awaits = &$this->awaits;
        $clone->trace = &$this->trace;
        $clone->input = $input;
        return $clone;
    }

    public function getLastCompletionResultValues(): ?ValuesInterface
    {
        return $this->lastCompletionResult;
    }

    public function getLastCompletionResult($type = null)
    {
        if ($this->lastCompletionResult === null) {
            return null;
        }

        return $this->lastCompletionResult->getValue(0, $type);
    }

    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    public function registerQuery(string $queryType, callable $handler): WorkflowContextInterface
    {
        $this->getWorkflowInstance()->addQueryHandler($queryType, $handler);

        return $this;
    }

    public function registerSignal(string $queryType, callable $handler): WorkflowContextInterface
    {
        $this->getWorkflowInstance()->addSignalHandler($queryType, $handler);

        return $this;
    }

    public function registerDynamicSignal(callable $handler): WorkflowContextInterface
    {
        $this->getWorkflowInstance()->setDynamicSignalHandler($handler);

        return $this;
    }

    public function registerDynamicQuery(callable $handler): WorkflowContextInterface
    {
        $this->getWorkflowInstance()->setDynamicQueryHandler($handler);

        return $this;
    }

    public function registerDynamicUpdate(callable $handler, ?callable $validator = null): WorkflowContextInterface
    {
        $this->getWorkflowInstance()->setDynamicUpdateHandler($handler, $validator);

        return $this;
    }

    public function registerUpdate(string $name, callable $handler, ?callable $validator): static
    {
        $this->getWorkflowInstance()->addUpdateHandler($name, $handler);
        $this->getWorkflowInstance()->addValidateUpdateHandler($name, $validator ?? static fn() => null);

        return $this;
    }

    public function getVersion(string $changeId, int $minSupported, int $maxSupported): Promise
    {
        return $this->callsInterceptor->with(
            fn(GetVersionInput $input): Promise => EncodedValues::decodePromise(
                $this->request(new GetVersion($input->changeId, $input->minSupported, $input->maxSupported)),
                Type::TYPE_ANY,
            ),
            /** @see WorkflowOutboundCallsInterceptor::getVersion() */
            'getVersion',
        )(new GetVersionInput($changeId, $minSupported, $maxSupported));
    }

    public function sideEffect(callable $context): Promise
    {
        $value = null;
        $closure = $context(...);

        try {
            if (!$this->isReplaying()) {
                $value = $this->callsInterceptor->with(
                    $closure,
                    /** @see WorkflowOutboundCallsInterceptor::sideEffect() */
                    'sideEffect',
                )(new SideEffectInput($closure));
            }
        } catch (\Throwable $e) {
            return Promise::reject($e);
        }

        $returnType = null;
        try {
            $reflection = new \ReflectionFunction($closure);
            $returnType = $reflection->getReturnType();
        } catch (\Throwable) {
        }

        $last = fn(): Promise => EncodedValues::decodePromise(
            $this->request(new SideEffect(EncodedValues::fromValues([$value]))),
            $returnType,
        );
        return $last();
    }

    public function isReplaying(): bool
    {
        return $this->services->env->isReplaying();
    }

    public function complete(?array $result = null, ?\Throwable $failure = null): Promise
    {
        if ($failure !== null) {
            $this->workflowInstance->clearSignalQueue();
        }

        return $this->callsInterceptor->with(
            function (CompleteInput $input): Promise {
                $values = $input->result !== null
                    ? EncodedValues::fromValues($input->result)
                    : EncodedValues::empty();

                return $this->request(new CompleteWorkflow($values, $input->failure), false);
            },
            /** @see WorkflowOutboundCallsInterceptor::complete() */
            'complete',
        )(new CompleteInput($result, $failure));
    }

    public function panic(?\Throwable $failure = null): Promise
    {
        return $this->callsInterceptor->with(
            fn(PanicInput $failure): Promise => $this->request(new Panic($failure->failure), false),
            /** @see WorkflowOutboundCallsInterceptor::panic() */
            'panic',
        )(new PanicInput($failure));
    }

    public function continueAsNew(
        string $type,
        array $args = [],
        ?ContinueAsNewOptions $options = null,
    ): Promise {
        return $this->callsInterceptor->with(
            function (ContinueAsNewInput $input): Promise {
                $this->continueAsNew = true;

                $request = new ContinueAsNew(
                    $input->type,
                    EncodedValues::fromValues($input->args),
                    $this->services->marshaller->marshal($input->options ?? new ContinueAsNewOptions()),
                    $this->getHeader(),
                );

                // must not be captured
                return $this->request($request, false);
            },
            /** @see WorkflowOutboundCallsInterceptor::continueAsNew() */
            'continueAsNew',
        )(new ContinueAsNewInput($type, $args, $options));
    }

    public function newContinueAsNewStub(string $class, ?ContinueAsNewOptions $options = null): object
    {
        $options ??= new ContinueAsNewOptions();

        $workflow = $this->services->workflowsReader->fromClass($class);

        return new ContinueAsNewProxy($class, $workflow, $options, $this);
    }

    public function isContinuedAsNew(): bool
    {
        return $this->continueAsNew;
    }

    public function executeChildWorkflow(
        string $type,
        array $args = [],
        ?ChildWorkflowOptions $options = null,
        mixed $returnType = null,
    ): Promise {
        return $this->callsInterceptor->with(
            fn(ExecuteChildWorkflowInput $input): Promise => $this
                ->newUntypedChildWorkflowStub($input->type, $input->options)
                ->execute($input->args, $input->returnType),
            /** @see WorkflowOutboundCallsInterceptor::executeChildWorkflow() */
            'executeChildWorkflow',
        )(new ExecuteChildWorkflowInput($type, $args, $options, $returnType));
    }

    public function newUntypedChildWorkflowStub(
        string $type,
        ?ChildWorkflowOptions $options = null,
    ): ChildWorkflowStubInterface {
        $options ??= new ChildWorkflowOptions();

        return new ChildWorkflowStub($this->services->marshaller, $type, $options, $this->getHeader());
    }

    public function newChildWorkflowStub(
        string $class,
        ?ChildWorkflowOptions $options = null,
    ): object {
        $workflow = $this->services->workflowsReader->fromClass($class);
        $options = $options ?? (new ChildWorkflowOptions())
            ->withNamespace($this->getInfo()->namespace);

        return new ChildWorkflowProxy(
            $class,
            $workflow,
            $options,
            $this,
        );
    }

    public function newExternalWorkflowStub(string $class, WorkflowExecution $execution): object
    {
        $workflow = $this->services->workflowsReader->fromClass($class);

        $stub = $this->newUntypedExternalWorkflowStub($execution);

        return new ExternalWorkflowProxy($class, $workflow, $stub);
    }

    public function newUntypedExternalWorkflowStub(WorkflowExecution $execution): ExternalWorkflowStubInterface
    {
        return new ExternalWorkflowStub($execution, $this->callsInterceptor);
    }

    public function executeActivity(
        string $type,
        array $args = [],
        ?ActivityOptionsInterface $options = null,
        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
    ): Promise {
        $isLocal = $options instanceof LocalActivityOptions;

        return $isLocal
            ? $this->callsInterceptor->with(
                fn(ExecuteLocalActivityInput $input): Promise => $this
                    ->newUntypedActivityStub($input->options)
                    ->execute($input->type, $input->args, $input->returnType, true),
                /** @see WorkflowOutboundCallsInterceptor::executeLocalActivity() */
                'executeLocalActivity',
            )(new ExecuteLocalActivityInput($type, $args, $options, $returnType))
            : $this->callsInterceptor->with(
                fn(ExecuteActivityInput $input): Promise => $this
                    ->newUntypedActivityStub($input->options)
                    ->execute($input->type, $input->args, $input->returnType),
                /** @see WorkflowOutboundCallsInterceptor::executeActivity() */
                'executeActivity',
            )(new ExecuteActivityInput($type, $args, $options, $returnType));
    }

    public function newUntypedActivityStub(
        ?ActivityOptionsInterface $options = null,
    ): ActivityStubInterface {
        $options ??= new ActivityOptions();

        return new ActivityStub($this->services->marshaller, $options, $this->getHeader());
    }

    public function newActivityStub(
        string $class,
        ?ActivityOptionsInterface $options = null,
    ): ActivityProxy {
        $activities = $this->services->activitiesReader->fromClass($class);

        if (isset($activities[0]) && $activities[0]->isLocalActivity() && !$options instanceof LocalActivityOptions) {
            throw new \RuntimeException("Local activity can be used only with LocalActivityOptions");
        }

        return new ActivityProxy(
            $class,
            $activities,
            $options ?? ActivityOptions::new(),
            $this,
            $this->callsInterceptor,
        );
    }

    public function timer($interval): Promise
    {
        $dateInterval = DateInterval::parse($interval, DateInterval::FORMAT_SECONDS);

        return $this->callsInterceptor->with(
            fn(TimerInput $input): Promise => $this->request(new NewTimer($input->interval)),
            /** @see WorkflowOutboundCallsInterceptor::timer() */
            'timer',
        )(new TimerInput($dateInterval));
    }

    public function request(
        RequestInterface $request,
        bool $cancellable = true,
        bool $waitResponse = true,
    ): Promise {
        $this->recordTrace();

        // Intercept workflow outbound calls
        return $this->requestInterceptor->with(
            function (RequestInterface $request) use ($waitResponse): Promise {
                if (!$waitResponse) {
                    $this->client->send($request);
                    return Promise::resolve();
                }

                return $this->client->request($request, $this);
            },
            /** @see WorkflowOutboundRequestInterceptor::handleOutboundRequest() */
            'handleOutboundRequest',
        )($request);
    }

    public function getStackTrace(): string
    {
        return StackRenderer::renderTrace($this->trace);
    }

    public function allHandlersFinished(): bool
    {
        return !$this->handlers->hasRunningHandlers();
    }

    public function upsertMemo(array $values): void
    {
        $this->callsInterceptor->with(
            function (UpsertMemoInput $input): Promise {
                if ($input->memo === []) {
                    return Promise::resolve();
                }

                $result = $this->request(new UpsertMemo($input->memo), false, false);

                /** @psalm-suppress UnsupportedPropertyReferenceUsage $memo */
                $memo = &$this->input->info->memo;
                $memo ??= [];
                foreach ($input->memo as $name => $value) {
                    if ($value === null) {
                        unset($memo[$name]);
                        continue;
                    }

                    $memo[$name] = $value;
                }

                return $result;
            },
            /** @see WorkflowOutboundCallsInterceptor::upsertMemo() */
            'upsertMemo',
        )(new UpsertMemoInput($values));
    }

    public function upsertSearchAttributes(array $searchAttributes): void
    {
        $this->callsInterceptor->with(
            function (UpsertSearchAttributesInput $input): Promise {
                if ($input->searchAttributes === []) {
                    return Promise::resolve();
                }

                $result = $this->request(new UpsertSearchAttributes($input->searchAttributes), false, false);

                /** @psalm-suppress UnsupportedPropertyReferenceUsage $sa */
                $sa = &$this->input->info->searchAttributes;
                foreach ($input->searchAttributes as $name => $value) {
                    if ($value === null) {
                        unset($sa[$name]);
                        continue;
                    }

                    $sa[$name] = $value;
                }

                return $result;
            },
            /** @see WorkflowOutboundCallsInterceptor::upsertSearchAttributes() */
            'upsertSearchAttributes',
        )(new UpsertSearchAttributesInput($searchAttributes));
    }

    public function upsertTypedSearchAttributes(SearchAttributeUpdate ...$updates): void
    {
        $this->callsInterceptor->with(
            function (UpsertTypedSearchAttributesInput $input): Promise {
                if ($input->updates === []) {
                    return Promise::resolve();
                }

                $result = $this->request(new UpsertTypedSearchAttributes($input->updates), false, false);

                // Merge changes
                $tsa = $this->input->info->typedSearchAttributes;
                foreach ($input->updates as $update) {
                    if ($update instanceof SearchAttributeUpdate\ValueUnset) {
                        $tsa = $tsa->withoutValue($update->name);
                        continue;
                    }

                    if ($update instanceof SearchAttributeUpdate\ValueSet) {
                        $tsa = $tsa->withValue(
                            SearchAttributeKey::for($update->type, $update->name),
                            $update->value,
                        );
                    }
                }

                $this->input->info->typedSearchAttributes = $tsa;
                return $result;
            },
            /** @see WorkflowOutboundCallsInterceptor::upsertTypedSearchAttributes() */
            'upsertTypedSearchAttributes',
        )(new UpsertTypedSearchAttributesInput($updates));
    }

    public function await(callable|Mutex|Promise|Future ...$conditions): Promise
    {
        return $this->callsInterceptor->with(
            fn(AwaitInput $input): Promise => $this->awaitRequest(...$input->conditions),
            /** @see WorkflowOutboundCallsInterceptor::await() */
            'await',
        )(new AwaitInput($conditions));
    }

    public function awaitWithTimeout($interval, callable|Mutex|Promise|Future ...$conditions): Promise
    {
        $intervalObject = DateInterval::parse($interval, DateInterval::FORMAT_SECONDS);

        return $this->callsInterceptor->with(
            function (AwaitWithTimeoutInput $input): Promise {
                /** Bypassing {@see timer()} to acquire a timer request ID */
                $request = new NewTimer($input->interval);
                $requestId = $request->getID();
                $timer = $this->request($request);
                \assert($timer instanceof CompletableResultInterface);

                return $this->awaitRequest($timer, ...$input->conditions)
                    ->map(function () use ($timer, $requestId): bool {
                        $isCompleted = $timer->isComplete();
                        if (!$isCompleted) {
                            // If internal timer was not completed then cancel it
                            $this->request(new Cancel($requestId));
                        }
                        return !$isCompleted;
                    });
            },
            /** @see WorkflowOutboundCallsInterceptor::awaitWithTimeout() */
            'awaitWithTimeout',
        )(new AwaitWithTimeoutInput($intervalObject, $conditions));
    }

    /**
     * Calculate unblocked conditions.
     */
    public function resolveConditions(): void
    {
        foreach ($this->awaits as $awaitsGroupId => $awaitsGroup) {
            foreach ($awaitsGroup as $i => [$condition, $deferred]) {
                if ($condition()) {
                    unset($this->awaits[$awaitsGroupId][$i]);
                    $deferred->resolve();
                    $this->resolveConditionGroup($awaitsGroupId);
                }
            }
        }
    }

    public function resolveConditionGroup(string $conditionGroupId): void
    {
        unset($this->awaits[$conditionGroupId]);
    }

    public function rejectConditionGroup(string $conditionGroupId): void
    {
        unset($this->awaits[$conditionGroupId]);
    }

    public function uuid(): Promise
    {
        return $this->sideEffect(static fn(): UuidInterface => \Ramsey\Uuid\Uuid::uuid4());
    }

    public function uuid4(): Promise
    {
        return $this->sideEffect(static fn(): UuidInterface => \Ramsey\Uuid\Uuid::uuid4());
    }

    public function uuid7(?\DateTimeInterface $dateTime = null): Promise
    {
        return $this->sideEffect(static fn(): UuidInterface => \Ramsey\Uuid\Uuid::uuid7($dateTime));
    }

    public function getLogger(): LoggerInterface
    {
        return $this->services->logger;
    }

    /**
     * @internal
     */
    public function getHandlerState(): HandlerState
    {
        return $this->handlers;
    }

    /**
     * @internal
     */
    #[\Override]
    public function destroy(): void
    {
        $this->awaits = [];
        $this->workflowInstance->destroy();
        unset($this->workflowInstance);
    }

    /**
     * @return Promise<mixed>
     */
    protected function awaitRequest(callable|Mutex|Promise|Future ...$conditions): Promise
    {
        $result = [];
        $conditionGroupId = Uuid::v4();

        foreach ($conditions as $condition) {
            // Wrap Mutex into callable
            $condition instanceof Mutex and $condition = static fn(): bool => !$condition->isLocked();

            if ($condition instanceof \Closure) {
                $callableResult = $condition($conditionGroupId);
                if ($callableResult === true) {
                    $this->resolveConditionGroup($conditionGroupId);
                    return Promise::resolve(true);
                }
                $result[] = $this->addCondition($conditionGroupId, $condition);
                continue;
            }

            if ($condition instanceof Promise || $condition instanceof Future) {
                $result[] = Promise::fromReactPromise($condition);
            }
        }

        if (\count($result) === 1) {
            return $result[0];
        }

        return Promise::any($result)->then(
            function ($result) use ($conditionGroupId) {
                $this->resolveConditionGroup($conditionGroupId);
                return $result;
            },
            function ($reason) use ($conditionGroupId): mixed {
                $this->rejectConditionGroup($conditionGroupId);
                
                // Handle CompositeException from Promise::any
                if ($reason instanceof \Temporal\Internal\Promise\CompositeException) {
                    foreach ($reason as $exception) {
                        if ($exception instanceof \Throwable) {
                            throw $exception;
                        }
                    }
                }
                
                throw $reason;
            },
        );
    }

    /**
     * @param non-empty-string $conditionGroupId
     * @return Promise<mixed>
     */
    protected function addCondition(string $conditionGroupId, callable $condition): Promise
    {
        $deferred = new DeferredPromise();
        $this->awaits[$conditionGroupId][] = [$condition, $deferred];

        return $deferred->promise();
    }

    /**
     * Record last stack trace of the call.
     */
    protected function recordTrace(): void
    {
        $this->trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
    }
}