<?php

declare(strict_types=1);

namespace Temporal\Testing;

final class SystemInfo
{
    private const OS_DARWIN = 'darwin';
    private const OS_LINUX = 'linux';
    private const OS_WINDOWS = 'windows';
    private const PLATFORM_MAPPINGS = [
        self::OS_DARWIN => 'macOS',
        self::OS_LINUX => 'linux',
        self::OS_WINDOWS => 'windows',
    ];
    private const ARCHITECTURE_MAPPINGS = [
        'x64' => 'amd64',
        'x86_64' => 'amd64',
        'amd64' => 'amd64',
        'aarch64' => 'arm64',
        'arm64' => 'arm64',
    ];
    private const TEMPORAL_EXECUTABLE_MAP = [
        self::OS_DARWIN => './temporal-test-server',
        self::OS_LINUX => './temporal-test-server',
        self::OS_WINDOWS => 'temporal-test-server.exe',
    ];
    private const TEMPORAL_CLI_EXECUTABLE_MAP = [
        self::OS_DARWIN => './temporal',
        self::OS_LINUX => './temporal',
        self::OS_WINDOWS => 'temporal.exe',
    ];

    private function __construct(
        public string $arch,
        public string $platform,
        public string $os,
        public string $temporalServerExecutable,
        public string $temporalCliExecutable = 'temporal',
    ) {}

    public static function detect(): self
    {
        $os = match (\PHP_OS_FAMILY) {
            'Darwin' => self::OS_DARWIN,
            'Windows' => self::OS_WINDOWS,
            default => self::OS_LINUX,
        };
        $machine = \strtolower(\php_uname('m'));
        $architecture = self::ARCHITECTURE_MAPPINGS[$machine] ?? 'amd64';

        return new self(
            $architecture,
            self::PLATFORM_MAPPINGS[$os],
            $os,
            self::TEMPORAL_EXECUTABLE_MAP[$os],
            self::TEMPORAL_CLI_EXECUTABLE_MAP[$os],
        );
    }
}
