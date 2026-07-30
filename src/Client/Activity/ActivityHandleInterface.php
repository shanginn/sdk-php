<?php

declare(strict_types=1);

namespace Temporal\Client\Activity;

/**
 * A durable reference to a standalone Activity execution.
 *
 * @experimental Standalone Activities are a Temporal Server Public Preview feature.
 */
interface ActivityHandleInterface
{
    public function getId(): string;

    public function getRunId(): ?string;

    public function getResult(mixed $type = null): mixed;

    public function describe(
        bool $includeInput = true,
        bool $includeOutcome = true,
    ): ActivityExecutionDescription;

    public function cancel(string $reason = ''): void;

    public function terminate(string $reason = ''): void;

    public function delete(): void;
}
