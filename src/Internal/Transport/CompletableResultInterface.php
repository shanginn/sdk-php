<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Transport;

use Temporal\Promise;

/**
 * @template T
 * @extends Promise<T>
 * @yield T
 */
interface CompletableResultInterface extends Promise
{
    /**
     * @return bool
     */
    public function isComplete(): bool;

    /**
     * @return mixed
     */
    public function getValue();
}