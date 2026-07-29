<?php

declare(strict_types=1);

namespace Temporal\Client\GRPC;

use TrueAsync\Temporal\Core\Connection as CoreConnection;

/**
 * Backwards-compatible explicit name for the native service client.
 *
 * {@see ServiceClient} itself is now backed by the TrueAsync Temporal core, so
 * new code can use `ServiceClient::create()` directly.
 */
final class TrueAsyncServiceClient extends ServiceClient
{
    public static function fromCore(CoreConnection $core): self
    {
        return new self($core);
    }
}
