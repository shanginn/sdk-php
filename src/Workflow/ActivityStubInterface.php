<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Workflow;

use React\Promise\PromiseInterface;
use Temporal\Activity\ActivityOptionsInterface;
use Temporal\DataConverter\Type;
use Temporal\Internal\Transport\CompletableResultInterface;

interface ActivityStubInterface
{
    public function getOptions(): ActivityOptionsInterface;

    /**
     * Executes an activity by its type name and arguments and suspends the
     * current workflow until the result is available.
     *
     * @param string $name name of an activity type to execute.
     * @param array $args arguments of the activity.
     */
    public function execute(
        string $name,
        array $args = [],
        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
        bool $isLocalActivity = false,
    ): mixed;

    /**
     * Schedules an activity without awaiting its result.
     *
     * @internal The workflow runtime uses this promise-based form to compose
     * deterministic commands. Application workflows should use {@see execute()}.
     * @return CompletableResultInterface Promise to the activity result.
     */
    public function executeAsync(
        string $name,
        array $args = [],
        Type|string|\ReflectionClass|\ReflectionType|null $returnType = null,
        bool $isLocalActivity = false,
    ): PromiseInterface;
}
