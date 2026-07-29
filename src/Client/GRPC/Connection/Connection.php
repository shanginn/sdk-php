<?php

declare(strict_types=1);

namespace Temporal\Client\GRPC\Connection;

use Temporal\Client\Common\ServerCapabilities;
use TrueAsync\Temporal\Core\Connection as CoreConnection;

/**
 * Compatibility wrapper around the native TrueAsync Temporal connection.
 *
 * The public SDK historically exposed this interface from the `GRPC`
 * namespace. Keeping the wrapper preserves that API while the transport is now
 * the Rust core bridge rather than a PHP gRPC channel.
 */
final class Connection implements ConnectionInterface
{
    public ?ServerCapabilities $capabilities = null;
    private bool $closed = false;

    /**
     * @internal Passing no Core connection is reserved for isolated transport
     * tests whose client overrides the wire call.
     */
    public function __construct(private readonly ?CoreConnection $core = null) {}

    public function isConnected(): bool
    {
        return !$this->closed;
    }

    public function connect(float $timeout): void
    {
        $this->closed = false;
    }

    public function disconnect(): void
    {
        $this->closed = true;
        $this->capabilities = null;
    }

    public function getCore(): CoreConnection
    {
        if ($this->closed) {
            throw new \LogicException('The Temporal service connection is closed.');
        }
        if ($this->core === null) {
            throw new \LogicException('This isolated Temporal connection has no native Core transport.');
        }

        return $this->core;
    }
}
