<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\Worker\Transport\RPCConnectionInterface;

/**
 * A placeholder RPC channel for workflow-only worker factories (the
 * deterministic engine makes no RPCs). Workers serving activities use
 * {@see CoreRpcConnection}, which answers heartbeats through the core; any
 * call here is an unwired path rather than a silent no-op.
 */
final class NullRpcConnection implements RPCConnectionInterface
{
    public function call(string $method, $payload): mixed
    {
        throw new \LogicException("RPC channel not wired to the core (called: {$method})");
    }
}
