<?php

declare(strict_types=1);

namespace Temporal\Tests;

use Temporal\Api\Operatorservice\V1\AddSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesResponse;
use Temporal\Internal\Transport\NativeUnaryClient;
use Temporal\Testing\TemporalServer;
use TrueAsync\Temporal\Core\Connection;

final class SearchAttributeTestInvoker
{
    public function __invoke(): void
    {
        $operation = new NativeUnaryClient(
            new Connection(TemporalServer::address()),
            NativeUnaryClient::SERVICE_OPERATOR,
        );
        $operation->call(
            'AddSearchAttributes',
            new AddSearchAttributesRequest(
                [
                    'search_attributes' => [
                        'attr1' => 2, // Keyword
                        'attr2' => 5, // Bool
                    ]
                ]
            ),
            AddSearchAttributesResponse::class,
        );
    }
}
