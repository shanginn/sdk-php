<?php

declare(strict_types=1);

namespace Temporal\Worker;

/**
 * Small process-safe state file used by the native testing helpers.
 *
 * @internal
 */
final class SharedFileCache
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $path ??= \getenv('TEMPORAL_TEST_CACHE_FILE') ?: null;
        $this->path = $path ?? \getcwd() . '/runtime/temporal-test-cache.bin';
    }

    public function clear(string $namespace): void
    {
        $this->mutate(static function (array &$state) use ($namespace): void {
            unset($state[$namespace]);
        });
    }

    public function read(string $namespace, mixed $default): mixed
    {
        return $this->mutate(
            static fn(array &$state): mixed => $state[$namespace] ?? $default,
        );
    }

    /**
     * @template T
     * @param callable(mixed&): T $callback
     * @return T
     */
    public function update(string $namespace, mixed $default, callable $callback): mixed
    {
        return $this->mutate(static function (array &$state) use ($namespace, $default, $callback): mixed {
            $state[$namespace] ??= $default;
            return $callback($state[$namespace]);
        });
    }

    /**
     * @template T
     * @param callable(array<string, mixed>&): T $callback
     * @return T
     */
    private function mutate(callable $callback): mixed
    {
        $directory = \dirname($this->path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Cannot create Temporal test-cache directory {$directory}.");
        }

        $file = @\fopen($this->path, 'c+b');
        if ($file === false) {
            throw new \RuntimeException("Cannot open Temporal test cache {$this->path}.");
        }

        try {
            if (!\flock($file, \LOCK_EX)) {
                throw new \RuntimeException("Cannot lock Temporal test cache {$this->path}.");
            }

            \rewind($file);
            $bytes = \stream_get_contents($file);
            $state = $bytes === '' || $bytes === false
                ? []
                : \unserialize($bytes, ['allowed_classes' => true]);
            if (!\is_array($state)) {
                throw new \RuntimeException("Temporal test cache {$this->path} is corrupt.");
            }

            $result = $callback($state);
            $serialized = \serialize($state);
            \rewind($file);
            if (!\ftruncate($file, 0) || \fwrite($file, $serialized) !== \strlen($serialized)) {
                throw new \RuntimeException("Cannot write Temporal test cache {$this->path}.");
            }
            \fflush($file);

            return $result;
        } finally {
            @\flock($file, \LOCK_UN);
            @\fclose($file);
        }
    }
}
