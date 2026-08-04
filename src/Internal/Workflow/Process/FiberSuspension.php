<?php

declare(strict_types=1);

namespace Temporal\Internal\Workflow\Process;

use React\Promise\PromiseInterface;

/**
 * A deterministic suspension instruction emitted only by {@see Awaiter}.
 *
 * @internal
 * @psalm-internal Temporal
 */
final readonly class FiberSuspension
{
    public function __construct(
        public PromiseInterface $promise,
        public bool $interruptOnCancel,
        public bool $preserveCancellationFailure = false,
    ) {}
}
