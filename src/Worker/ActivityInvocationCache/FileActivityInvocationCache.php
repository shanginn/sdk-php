<?php

declare(strict_types=1);

namespace Temporal\Worker\ActivityInvocationCache;

use React\Promise\PromiseInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\InvalidArgumentException;
use Temporal\Worker\InvocationFailure;
use Temporal\Worker\InvocationMatched;
use Temporal\Worker\InvocationResult;
use Temporal\Worker\InvocationResultQueue;
use Temporal\Worker\SharedFileCache;
use Temporal\Worker\Transport\Command\ServerRequestInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class FileActivityInvocationCache implements ActivityInvocationCacheInterface
{
    private const NAMESPACE = 'activities';

    private readonly SharedFileCache $store;
    private readonly DataConverterInterface $dataConverter;

    public function __construct(
        ?DataConverterInterface $dataConverter = null,
        ?string $path = null,
    ) {
        $this->dataConverter = $dataConverter ?? DataConverter::createDefault();
        $this->store = new SharedFileCache($path);
    }

    public function clear(): void
    {
        $this->store->clear(self::NAMESPACE);
    }

    public function saveCompletion(string $activityMethodName, mixed $value): void
    {
        $result = InvocationResult::fromValue($value, $this->dataConverter);
        $this->store->update(self::NAMESPACE, [], static function (array &$cache) use (
            $activityMethodName,
            $result,
        ): void {
            $cache[$activityMethodName] = $result;
        });
    }

    public function saveFailure(string $activityMethodName, \Throwable $error): void
    {
        $failure = InvocationFailure::fromThrowable($error, $this->dataConverter);
        $this->store->update(self::NAMESPACE, [], static function (array &$cache) use (
            $activityMethodName,
            $failure,
        ): void {
            $cache[$activityMethodName] = $failure;
        });
    }

    public function saveConsecutiveCompletions(string $activityMethodName, array $values): void
    {
        $items = \array_map(
            fn(mixed $value): InvocationResult => InvocationResult::fromValue($value, $this->dataConverter),
            $values,
        );
        $queue = new InvocationResultQueue($items);
        $this->store->update(self::NAMESPACE, [], static function (array &$cache) use (
            $activityMethodName,
            $queue,
        ): void {
            $cache[$activityMethodName] = $queue;
        });
    }

    public function saveCompletionWhen(string $activityMethodName, array $args, mixed $value): void
    {
        $payloads = EncodedValues::fromValues($args, $this->dataConverter)->toPayloads();
        $result = InvocationResult::fromValue($value, $this->dataConverter);
        $this->store->update(self::NAMESPACE, [], static function (array &$cache) use (
            $activityMethodName,
            $payloads,
            $result,
        ): void {
            $matched = $cache[$activityMethodName] ?? new InvocationMatched();
            if (!$matched instanceof InvocationMatched) {
                $matched = new InvocationMatched();
            }
            $matched->addCase($payloads, $result);
            $cache[$activityMethodName] = $matched;
        });
    }

    public function canHandle(ServerRequestInterface $request): bool
    {
        if (!\in_array($request->getName(), ['InvokeActivity', 'InvokeLocalActivity'], true)) {
            return false;
        }

        $name = $request->getOptions()['name'] ?? '';
        $cache = $this->store->read(self::NAMESPACE, []);

        return isset($cache[$name]);
    }

    public function execute(ServerRequestInterface $request): PromiseInterface
    {
        $name = $request->getOptions()['name'];
        $value = $this->store->update(
            self::NAMESPACE,
            [],
            static function (array &$cache) use ($name, $request): mixed {
                if (!isset($cache[$name])) {
                    throw new \LogicException(\sprintf('No mock stored for activity "%s"', $name));
                }

                $value = $cache[$name];
                if ($value instanceof InvocationMatched) {
                    return $value->match($request->getPayloads()->toPayloads());
                }
                if ($value instanceof InvocationResultQueue) {
                    $current = $value->current();
                    $value->advance();
                    $cache[$name] = $value;
                    return $current;
                }

                return $value;
            },
        );

        if ($value === null) {
            return reject(new InvalidArgumentException(
                \sprintf('No matching expectation for activity "%s"', $name),
            ));
        }

        return $value instanceof InvocationFailure
            ? reject($value->toThrowable($this->dataConverter))
            : resolve($value->toEncodedValues($this->dataConverter));
    }
}
