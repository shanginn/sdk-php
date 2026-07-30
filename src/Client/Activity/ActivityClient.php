<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Sdk\V1\UserMetadata;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionRequest;
use Temporal\Client\ClientOptions;
use Temporal\Client\Common\ClientContextTrait;
use Temporal\Client\Common\Paginator;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Common\Uuid;
use Temporal\DataConverter\ActivitySerializationContext;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\SerializationContextBinder;
use Temporal\Exception\Client\ActivityExecutionAlreadyStartedException;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Internal\Support\DateInterval;
use Temporal\Api\Errordetails\V1\ActivityExecutionAlreadyStartedFailure;

/**
 * Native client for Standalone Activities.
 *
 * This API does not introduce an implementation requirement on
 * {@see \Temporal\Client\WorkflowClientInterface}; applications can construct it
 * directly or use {@see \Temporal\Client\WorkflowClient::newActivityClient()}.
 *
 * @experimental Requires Temporal Server 1.31+ with Standalone Activities enabled.
 */
final class ActivityClient implements ActivityClientInterface
{
    use ClientContextTrait;

    private readonly ClientOptions $clientOptions;
    private readonly DataConverterInterface $converter;

    public function __construct(
        ServiceClientInterface $serviceClient,
        ?ClientOptions $options = null,
        ?DataConverterInterface $converter = null,
    ) {
        $this->clientOptions = $options ?? new ClientOptions();
        $this->converter = $converter ?? DataConverter::createDefault();

        $context = $serviceClient->getContext();
        $this->client = $serviceClient->withContext(
            $context->withMetadata(
                ['Temporal-Namespace' => [$this->clientOptions->namespace]] + $context->getMetadata(),
            ),
        );
    }

    public static function create(
        ServiceClientInterface $serviceClient,
        ?ClientOptions $options = null,
        ?DataConverterInterface $converter = null,
    ): self {
        return new self($serviceClient, $options, $converter);
    }

    public function start(
        string $activityType,
        ActivityOptions $options,
        mixed ...$arguments,
    ): ActivityHandleInterface {
        if ($activityType === '') {
            throw new \InvalidArgumentException('Standalone Activity type must not be empty.');
        }
        $options->validate();

        $serializationContext = new ActivitySerializationContext(
            namespace: $this->clientOptions->namespace,
            activityType: $activityType,
            taskQueue: $options->taskQueue,
            workflowId: null,
            workflowType: null,
            isLocal: false,
        );
        $converter = SerializationContextBinder::bind($this->converter, $serializationContext);

        $request = (new StartActivityExecutionRequest())
            ->setNamespace($this->clientOptions->namespace)
            ->setIdentity($this->clientOptions->identity)
            ->setRequestId($options->requestId ?? Uuid::v4())
            ->setActivityId($options->activityId)
            ->setActivityType((new ActivityType())->setName($activityType))
            ->setTaskQueue((new TaskQueue())->setName($options->taskQueue))
            ->setIdReusePolicy($options->idReusePolicy->value)
            ->setIdConflictPolicy($options->idConflictPolicy->value)
            ->setPriority($options->priority->toProto());

        self::setDuration($request, 'setScheduleToCloseTimeout', $options->scheduleToCloseTimeout);
        self::setDuration($request, 'setScheduleToStartTimeout', $options->scheduleToStartTimeout);
        self::setDuration($request, 'setStartToCloseTimeout', $options->startToCloseTimeout);
        self::setDuration($request, 'setHeartbeatTimeout', $options->heartbeatTimeout);
        self::setDuration($request, 'setStartDelay', $options->startDelay);

        $options->retryOptions === null
            or $request->setRetryPolicy($options->retryOptions->toWorkflowRetryPolicy());

        $input = EncodedValues::fromValues($arguments, $this->converter)
            ->withSerializationContext($serializationContext);
        $input->isEmpty() or $request->setInput($input->toPayloads());

        // Headers and Search Attributes are service metadata rather than
        // Activity-owned payloads, so they intentionally stay unbound.
        $searchAttributes = $options->toSearchAttributes($this->converter);
        $searchAttributes === null or $request->setSearchAttributes($searchAttributes);

        if (!$options->header->isEmpty()) {
            $options->header->setDataConverter($this->converter);
            $request->setHeader($options->header->toHeader());
        }

        if ($options->summary !== '' || $options->details !== '') {
            $metadata = new UserMetadata();
            $options->summary === '' or $metadata->setSummary($converter->toPayload($options->summary));
            $options->details === '' or $metadata->setDetails($converter->toPayload($options->details));
            $request->setUserMetadata($metadata);
        }

        try {
            $response = $this->client->StartActivityExecution($request);
        } catch (ServiceClientException $e) {
            $failure = $e->getFailure(ActivityExecutionAlreadyStartedFailure::class) ?? throw $e;
            \assert($failure instanceof ActivityExecutionAlreadyStartedFailure);

            throw new ActivityExecutionAlreadyStartedException(
                $options->activityId,
                $failure->getRunId(),
                $activityType,
                $e,
            );
        }

        return new ActivityHandle(
            $this->client,
            $this->clientOptions,
            $this->converter,
            $this->clientOptions->namespace,
            $options->activityId,
            $response->getRunId(),
            $response->getStarted() ? $activityType : null,
            $response->getStarted() ? $options->taskQueue : null,
        );
    }

    public function execute(
        string $activityType,
        ActivityOptions $options,
        mixed ...$arguments,
    ): mixed {
        return $this->start($activityType, $options, ...$arguments)->getResult();
    }

    public function getHandle(
        string $activityId,
        ?string $runId = null,
        ?string $namespace = null,
    ): ActivityHandleInterface {
        if ($activityId === '') {
            throw new \InvalidArgumentException('Standalone Activity ID must not be empty.');
        }
        if ($runId === '') {
            $runId = null;
        }

        return new ActivityHandle(
            $this->client,
            $this->clientOptions,
            $this->converter,
            $namespace ?? $this->clientOptions->namespace,
            $activityId,
            $runId,
        );
    }

    public function list(
        string $query = '',
        ?string $namespace = null,
        int $pageSize = 100,
    ): Paginator {
        if ($pageSize <= 0) {
            throw new \InvalidArgumentException('Page size must be greater than zero.');
        }

        $namespace ??= $this->clientOptions->namespace;
        $request = (new ListActivityExecutionsRequest())
            ->setNamespace($namespace)
            ->setPageSize($pageSize)
            ->setQuery($query);

        $loader = function () use ($request): \Generator {
            do {
                $response = $this->client->ListActivityExecutions($request);
                $nextPageToken = $response->getNextPageToken();
                $page = [];
                foreach ($response->getExecutions() as $execution) {
                    $page[] = new ActivityExecutionInfo($execution, $this->converter);
                }
                yield $page;
                $request->setNextPageToken($nextPageToken);
            } while ($nextPageToken !== '');
        };

        /** @return int<0, max> */
        $counter = function () use ($query, $namespace): int {
            $count = $this->count($query, $namespace)->count;
            \assert($count >= 0);

            return $count;
        };

        return Paginator::createFromGenerator($loader(), $counter);
    }

    public function count(string $query = '', ?string $namespace = null): CountActivityExecutions
    {
        $namespace ??= $this->clientOptions->namespace;
        $response = $this->client->CountActivityExecutions(
            (new CountActivityExecutionsRequest())
                ->setNamespace($namespace)
                ->setQuery($query),
        );

        $groups = [];
        foreach ($response->getGroups() as $group) {
            $values = [];
            foreach ($group->getGroupValues() as $payload) {
                // Aggregation groups can span Activity types and task queues,
                // so no truthful ActivitySerializationContext exists here.
                $values[] = $this->converter->fromPayload($payload, null);
            }
            $groups[] = new ActivityExecutionCountGroup($values, (int) $group->getCount());
        }

        return new CountActivityExecutions((int) $response->getCount(), $groups);
    }

    private static function setDuration(
        StartActivityExecutionRequest $request,
        string $setter,
        \DateInterval $duration,
    ): void {
        $proto = DateInterval::toDuration($duration, true);
        $proto === null or $request->{$setter}($proto);
    }
}
