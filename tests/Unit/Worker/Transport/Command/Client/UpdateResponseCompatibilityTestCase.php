<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\Transport\Command\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\EncodedValues;
use Temporal\Worker\Transport\Codec\JsonCodec\Encoder as JsonEncoder;
use Temporal\Worker\Transport\Codec\ProtoCodec\Encoder as ProtoEncoder;
use Temporal\Worker\Transport\Command\Client\UpdateResponse;
use Temporal\Worker\Transport\Command\ResponseInterface;

#[CoversClass(UpdateResponse::class)]
final class UpdateResponseCompatibilityTestCase extends TestCase
{
    public function testPreservesLegacyPublicContract(): void
    {
        $values = EncodedValues::fromValues(['result']);
        $failure = new \RuntimeException('failed');

        $response = new UpdateResponse(
            command: UpdateResponse::COMMAND_COMPLETED,
            values: $values,
            failure: $failure,
            updateId: 'update-id',
        );

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertTrue((new \ReflectionClass($response))->isFinal());
        self::assertSame('UpdateValidated', UpdateResponse::COMMAND_VALIDATED);
        self::assertSame('UpdateCompleted', UpdateResponse::COMMAND_COMPLETED);
        self::assertSame(0, $response->getID());
        self::assertSame('UpdateCompleted', $response->getCommand());
        self::assertSame($values, $response->getPayloads());
        self::assertSame($failure, $response->getFailure());
        self::assertSame(['id' => 'update-id'], $response->getOptions());
    }

    public function testPreservesIntegerUpdateId(): void
    {
        $response = new UpdateResponse(
            command: UpdateResponse::COMMAND_VALIDATED,
            values: null,
            failure: null,
            updateId: 42,
        );

        self::assertSame(['id' => 42], $response->getOptions());
    }

    public function testRemainsEncodableByBothTransports(): void
    {
        $converter = DataConverter::createDefault();
        $response = new UpdateResponse(
            command: UpdateResponse::COMMAND_COMPLETED,
            values: EncodedValues::fromValues(['result']),
            failure: null,
            updateId: 'update-id',
        );

        $json = (new JsonEncoder($converter))->encode($response);
        self::assertSame('UpdateCompleted', $json['command']);
        self::assertSame(['id' => 'update-id'], $json['options']);
        self::assertArrayHasKey('payloads', $json);

        $proto = (new ProtoEncoder($converter))->encode($response);
        self::assertSame('UpdateCompleted', $proto->getCommand());
        self::assertSame('{"id":"update-id"}', $proto->getOptions());
        self::assertNotNull($proto->getPayloads());
    }

    public function testProtoEncodingPreservesLegacyJsonFlags(): void
    {
        $response = new UpdateResponse(
            command: UpdateResponse::COMMAND_VALIDATED,
            values: null,
            failure: null,
            updateId: "идентификатор-\xFF",
        );

        $proto = (new ProtoEncoder(DataConverter::createDefault()))->encode($response);

        self::assertSame('{"id":"идентификатор-"}', $proto->getOptions());
    }
}
