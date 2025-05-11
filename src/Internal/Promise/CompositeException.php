<?php

declare(strict_types=1);

namespace Temporal\Internal\Promise;

/**
 * @internal
 * @psalm-internal Temporal
 */
class CompositeException extends \Exception implements \IteratorAggregate, \Countable
{
    /**
     * @var \Throwable[]
     */
    private array $exceptions;

    /**
     * @param \Throwable[] $exceptions
     */
    public function __construct(array $exceptions)
    {
        $this->exceptions = \array_values(\array_filter($exceptions, static function ($reason): bool {
            return $reason instanceof \Throwable;
        }));

        parent::__construct(
            'Multiple exceptions: ' . $this->buildExceptionMessage(),
            0,
            isset($this->exceptions[0]) ? $this->exceptions[0] : null
        );
    }

    /**
     * @return \Throwable[]
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return \count($this->exceptions);
    }

    /**
     * @return \ArrayIterator<int, \Throwable>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->exceptions);
    }

    /**
     * @return string
     */
    private function buildExceptionMessage(): string
    {
        $messages = [];

        foreach ($this->exceptions as $exception) {
            $messages[] = \sprintf(
                '[%s] %s',
                \get_class($exception),
                $exception->getMessage()
            );
        }

        return \implode(' | ', $messages);
    }
}