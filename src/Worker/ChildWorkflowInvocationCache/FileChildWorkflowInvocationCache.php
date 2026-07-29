<?php

declare(strict_types=1);

namespace Temporal\Worker\ChildWorkflowInvocationCache;

use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Worker\InvocationFailure;
use Temporal\Worker\InvocationMatched;
use Temporal\Worker\InvocationResult;
use Temporal\Worker\SharedFileCache;

final class FileChildWorkflowInvocationCache implements ChildWorkflowInvocationCacheInterface
{
    private const NAMESPACE = 'child-workflows';
    private const EMPTY = [
        'cache' => [],
        'versions' => [],
        'invoked' => [],
        'sideEffects' => [],
        'sideEffectCursor' => 0,
    ];

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

    public function recordInvoked(string $workflowType): void
    {
        $this->update(static function (array &$state) use ($workflowType): void {
            $state['invoked'][$workflowType] = true;
        });
    }

    public function wasInvoked(string $workflowType): bool
    {
        return (bool) ($this->state()['invoked'][$workflowType] ?? false);
    }

    public function saveCompletion(string $workflowType, mixed $value): void
    {
        $result = InvocationResult::fromValue($value, $this->dataConverter);
        $this->update(static function (array &$state) use ($workflowType, $result): void {
            $state['cache'][$workflowType] = $result;
        });
    }

    public function saveFailure(string $workflowType, \Throwable $error): void
    {
        $failure = InvocationFailure::fromThrowable($error, $this->dataConverter);
        $this->update(static function (array &$state) use ($workflowType, $failure): void {
            $state['cache'][$workflowType] = $failure;
        });
    }

    public function saveCompletionWhen(string $workflowType, array $args, mixed $value): void
    {
        $payloads = EncodedValues::fromValues($args, $this->dataConverter)->toPayloads();
        $result = InvocationResult::fromValue($value, $this->dataConverter);
        $this->update(static function (array &$state) use (
            $workflowType,
            $payloads,
            $result,
        ): void {
            $matched = $state['cache'][$workflowType] ?? new InvocationMatched();
            if (!$matched instanceof InvocationMatched) {
                $matched = new InvocationMatched();
            }
            $matched->addCase($payloads, $result);
            $state['cache'][$workflowType] = $matched;
        });
    }

    public function has(string $workflowType): bool
    {
        return isset($this->state()['cache'][$workflowType]);
    }

    public function get(string $workflowType): InvocationResult|InvocationFailure|InvocationMatched
    {
        $value = $this->state()['cache'][$workflowType] ?? null;
        if (!$value instanceof InvocationResult
            && !$value instanceof InvocationFailure
            && !$value instanceof InvocationMatched
        ) {
            throw new \LogicException(\sprintf('No mock stored for child workflow "%s"', $workflowType));
        }

        return $value;
    }

    public function saveVersion(string $changeId, int $version): void
    {
        $this->update(static function (array &$state) use ($changeId, $version): void {
            $state['versions'][$changeId] = $version;
        });
    }

    public function hasVersion(string $changeId): bool
    {
        return isset($this->state()['versions'][$changeId]);
    }

    public function getVersion(string $changeId): int
    {
        return $this->state()['versions'][$changeId];
    }

    public function saveSideEffect(mixed $value): void
    {
        $result = InvocationResult::fromValue($value, $this->dataConverter);
        $this->update(static function (array &$state) use ($result): void {
            $state['sideEffects'][] = $result;
        });
    }

    public function hasSideEffect(): bool
    {
        $state = $this->state();
        return $state['sideEffectCursor'] < \count($state['sideEffects']);
    }

    public function nextSideEffect(): mixed
    {
        $result = $this->update(static function (array &$state): InvocationResult {
            if ($state['sideEffects'] === []) {
                throw new \LogicException('No mocked side effect is available.');
            }
            $index = \min($state['sideEffectCursor'], \count($state['sideEffects']) - 1);
            ++$state['sideEffectCursor'];
            return $state['sideEffects'][$index];
        });

        return $result->toValue(null, $this->dataConverter);
    }

    private function state(): array
    {
        return $this->store->read(self::NAMESPACE, self::EMPTY);
    }

    private function update(callable $callback): mixed
    {
        return $this->store->update(self::NAMESPACE, self::EMPTY, $callback);
    }
}
