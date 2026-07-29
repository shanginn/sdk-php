<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\App\Runtime;

/**
 * Minimal process-safe key/value store for acceptance tests that coordinate a
 * PHPUnit process with a standalone native worker.
 */
final class SharedStore
{
    public function __construct(private readonly string $path)
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Cannot create shared-store directory {$directory}.");
        }
    }

    public static function fromEnvironment(string $workDir): self
    {
        $path = \getenv('TEMPORAL_TEST_SHARED_STORE');

        return new self(
            \is_string($path) && $path !== '' ? $path : self::defaultPath($workDir),
        );
    }

    public static function defaultPath(string $workDir): string
    {
        return $workDir . DIRECTORY_SEPARATOR . 'runtime/tests/native-worker-store.json';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->read(static fn(array $values): mixed => $values[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->write(static function (array &$values) use ($key, $value): void {
            $values[$key] = $value;
        });
    }

    public function clear(): void
    {
        $this->write(static function (array &$values): void {
            $values = [];
        });
    }

    private function read(callable $reader): mixed
    {
        $stream = \fopen($this->path, 'c+');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open shared store {$this->path}.");
        }

        try {
            \flock($stream, \LOCK_SH);
            $values = $this->decode($stream);

            return $reader($values);
        } finally {
            \flock($stream, \LOCK_UN);
            \fclose($stream);
        }
    }

    private function write(callable $writer): void
    {
        $stream = \fopen($this->path, 'c+');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open shared store {$this->path}.");
        }

        try {
            \flock($stream, \LOCK_EX);
            $values = $this->decode($stream);
            $writer($values);
            \rewind($stream);
            \ftruncate($stream, 0);
            \fwrite($stream, \json_encode($values, \JSON_THROW_ON_ERROR));
            \fflush($stream);
        } finally {
            \flock($stream, \LOCK_UN);
            \fclose($stream);
        }
    }

    /** @return array<string, mixed> */
    private function decode(mixed $stream): array
    {
        \rewind($stream);
        $contents = \stream_get_contents($stream);
        if ($contents === false || $contents === '') {
            return [];
        }

        $values = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($values) ? $values : [];
    }
}
