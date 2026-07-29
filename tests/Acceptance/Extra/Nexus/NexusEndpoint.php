<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus;

final class NexusEndpoint
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int|string $version,
    ) {}
}
