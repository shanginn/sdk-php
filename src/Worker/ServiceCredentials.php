<?php

declare(strict_types=1);

namespace Temporal\Worker;

use Temporal\Internal\Traits\CloneWith;

/**
 * Credential configuration used when creating a native Temporal Core connection.
 */
final class ServiceCredentials
{
    use CloneWith;

    public readonly string $apiKey;

    private function __construct()
    {
        $this->apiKey = '';
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Set the authentication token for API calls.
     *
     * Create a new factory/connection when rotating a worker API key. Client
     * calls may use {@see \Temporal\Client\GRPC\BaseClient::withAuthKey()} for a
     * dynamically resolved token.
     *
     * @link https://docs.temporal.io/cloud/api-keys
     * @since SDK 2.12.0
     */
    public function withApiKey(string $key): static
    {
        /** @see self::$apiKey */
        return $this->cloneWith('apiKey', $key);
    }
}
