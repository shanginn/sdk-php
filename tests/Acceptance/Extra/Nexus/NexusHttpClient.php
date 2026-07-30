<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\Extra\Nexus;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Temporal\Nexus\Header;

final class NexusHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
    ) {}

    /**
     * POST a Nexus operation. Returns [http_code, body, headers-lowercased-keys].
     *
     * The Temporal frontend's HTTP endpoint cache is lazy: a freshly-created
     * endpoint can return 404 for a few ms after CreateNexusEndpoint succeeds.
     * Mirrors Go SDK's nexus_test helper (10 attempts × 100ms).
     *
     * @param array<string, string> $headers
     * @return array{int, string, array<string, list<string>>}
     */
    public function post(
        NexusEndpoint $endpoint,
        string $service,
        string $operation,
        mixed $body,
        array $headers = [],
    ): array {
        $headers = self::withRequestId($headers);

        $attempts = 10;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->http->request(
                'POST',
                "/nexus/endpoints/{$endpoint->id}/services/{$service}/{$operation}",
                [
                    'headers' => $headers,
                    'json' => $body,
                    'max_duration' => 30,
                ],
            );
            $code = $response->getStatusCode();
            if ($code !== 404 || $attempt === $attempts) {
                return [$code, $response->getContent(false), $response->getHeaders(false)];
            }
            \usleep(100_000);
        }
        throw new \LogicException('Unreachable');
    }

    /**
     * Cancel a previously-started asynchronous Nexus operation.
     *
     * @param array<string, string> $headers
     * @return array{int, string, array<string, list<string>>}
     */
    public function cancel(
        NexusEndpoint $endpoint,
        string $service,
        string $operation,
        string $operationToken,
        array $headers = [],
    ): array {
        $response = $this->http->request(
            'POST',
            "/nexus/endpoints/{$endpoint->id}/services/{$service}/{$operation}/cancel",
            [
                'headers' => ['Nexus-Operation-Token' => $operationToken] + $headers,
                'max_duration' => 30,
            ],
        );

        return [
            $response->getStatusCode(),
            $response->getContent(false),
            $response->getHeaders(false),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function withRequestId(array $headers): array
    {
        foreach ($headers as $name => $_) {
            if (\strcasecmp($name, Header::REQUEST_ID) === 0) {
                return $headers;
            }
        }

        $headers[Header::REQUEST_ID] = 'acceptance-' . \bin2hex(\random_bytes(16));

        return $headers;
    }
}
