<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus;

use Symfony\Component\Process\Process;
use Temporal\Tests\Acceptance\App\Runtime\State;

final class NexusEndpoints
{
    private const COMMAND_TIMEOUT_SECONDS = 20;
    private const GET_ATTEMPTS = 10;

    /** @var list<NexusEndpoint> */
    private array $registered = [];

    public function __construct(
        private readonly State $state,
    ) {}

    public function register(
        string $namespace,
        string $taskQueue,
        string $prefix = 'test-nexus',
    ): NexusEndpoint {
        $name = $prefix . '-' . \bin2hex(\random_bytes(4));

        $this->run([
            'operator',
            'nexus',
            'endpoint',
            'create',
            '--name',
            $name,
            '--target-namespace',
            $namespace,
            '--target-task-queue',
            $taskQueue,
        ]);

        try {
            $registered = $this->get($name);
        } catch (\Throwable $e) {
            // A successful create followed by a failed lookup must not leak an
            // Endpoint into the developer's local Temporal Service.
            $this->delete($name, ignoreNotFound: true);
            throw $e;
        }

        $this->registered[] = $registered;

        return $registered;
    }

    /**
     * Remove every endpoint created since the previous cleanup.
     */
    public function cleanup(): void
    {
        while ($endpoint = \array_pop($this->registered)) {
            try {
                $this->delete($endpoint->name, ignoreNotFound: true);
            } catch (\Throwable $e) {
                $this->registered[] = $endpoint;
                throw $e;
            }
        }
    }

    private static function cliBinary(): string
    {
        $configured = \getenv('TEMPORAL_CLI_BINARY');
        if (\is_string($configured) && $configured !== '') {
            return $configured;
        }

        $filename = PHP_OS_FAMILY === 'Windows' ? 'temporal.exe' : 'temporal';
        $repositoryBinary = \dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . $filename;

        return \is_file($repositoryBinary) ? $repositoryBinary : $filename;
    }

    private function get(string $name): NexusEndpoint
    {
        $lastFailure = null;

        for ($attempt = 1; $attempt <= self::GET_ATTEMPTS; $attempt++) {
            $process = $this->process([
                'operator',
                'nexus',
                'endpoint',
                'get',
                '--name',
                $name,
                '--output',
                'json',
            ]);
            $process->run();

            if ($process->isSuccessful()) {
                return $this->decodeEndpoint($name, $process->getOutput());
            }

            $lastFailure = $this->failure($process);
            if (!$this->isNotFound($process) || $attempt === self::GET_ATTEMPTS) {
                throw $lastFailure;
            }

            \usleep(100_000);
        }

        throw $lastFailure ?? new \LogicException('Nexus Endpoint lookup did not run.');
    }

    private function delete(string $name, bool $ignoreNotFound): void
    {
        $process = $this->process([
            'operator',
            'nexus',
            'endpoint',
            'delete',
            '--name',
            $name,
        ]);
        $process->run();

        if (!$process->isSuccessful() && (!$ignoreNotFound || !$this->isNotFound($process))) {
            throw $this->failure($process);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): string
    {
        $process = $this->process($arguments);
        $process->run();

        if (!$process->isSuccessful()) {
            throw $this->failure($process);
        }

        return \trim($process->getOutput());
    }

    /**
     * @param list<string> $arguments
     */
    private function process(array $arguments): Process
    {
        $process = new Process([
            self::cliBinary(),
            ...$arguments,
            '--address',
            $this->state->address,
            '--color',
            'never',
        ]);
        $process->setTimeout(self::COMMAND_TIMEOUT_SECONDS);

        return $process;
    }

    private function decodeEndpoint(string $name, string $json): NexusEndpoint
    {
        try {
            $decoded = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "Temporal CLI returned invalid JSON for Nexus Endpoint {$name}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException("Temporal CLI returned no Nexus Endpoint object for {$name}.");
        }

        $endpoint = $decoded['endpoint'] ?? $decoded;
        if (!\is_array($endpoint)) {
            throw new \RuntimeException("Temporal CLI returned a malformed Nexus Endpoint object for {$name}.");
        }

        $id = $endpoint['id'] ?? null;
        $version = $endpoint['version'] ?? null;
        if (
            (!\is_string($id) && !\is_int($id))
            || $id === ''
            || (!\is_string($version) && !\is_int($version))
        ) {
            throw new \RuntimeException(
                "Temporal CLI Nexus Endpoint response for {$name} is missing its id or version.",
            );
        }

        return new NexusEndpoint(
            id: (string) $id,
            name: $name,
            version: $version,
        );
    }

    private function failure(Process $process): \RuntimeException
    {
        $details = \trim($process->getErrorOutput() . "\n" . $process->getOutput());

        return new \RuntimeException(\sprintf(
            "Temporal CLI failed (%d): %s\n%s",
            $process->getExitCode(),
            $process->getCommandLine(),
            $details,
        ));
    }

    private function isNotFound(Process $process): bool
    {
        $output = \strtolower($process->getErrorOutput() . "\n" . $process->getOutput());

        return \str_contains($output, 'not found') || \str_contains($output, 'not_found');
    }
}
