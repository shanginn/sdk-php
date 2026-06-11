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
 * A placeholder RPC channel for the worker factory. The deterministic workflow
 * engine makes no RPCs, and activity heartbeats are not wired to the core yet;
 * any call here is a not-yet-supported path rather than a silent no-op.
 */
final class NullRpcConnection implements RPCConnectionInterface
{
    public function call(string $method, $payload): mixed
    {
        throw new \LogicException("RPC channel not wired to the core (called: {$method})");
    }
}
