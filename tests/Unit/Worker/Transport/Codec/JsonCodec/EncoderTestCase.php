<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Unit\Worker\Transport\Codec\JsonCodec;

use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Failure\V1\Failure;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\EncodedValues;
use Temporal\Worker\Transport\Codec\JsonCodec\Encoder;
use Temporal\Worker\Transport\Command\Client\CommandResponse;

final class EncoderTestCase extends TestCase
{
    public function testEncodesCommandResponseWithPayloads(): void
    {
        $result = (new Encoder(DataConverter::createDefault()))->encode(new CommandResponse(
            command: 'UpdateCompleted',
            options: ['id' => 'update-id'],
            payloads: EncodedValues::fromValues(['result']),
        ));

        $this->assertSame('UpdateCompleted', $result['command']);
        $this->assertSame(['id' => 'update-id'], $result['options']);
        $this->assertArrayNotHasKey('id', $result);

        $payloads = new Payloads();
        $payloads->mergeFromString(\base64_decode($result['payloads'], true));
        $this->assertSame(
            'result',
            DataConverter::createDefault()->fromPayload($payloads->getPayloads()[0], 'string'),
        );
    }

    public function testEncodesCommandResponseWithFailureAndEmptyOptions(): void
    {
        $result = (new Encoder(DataConverter::createDefault()))->encode(new CommandResponse(
            command: 'NexusOperationStarted',
            failure: new \RuntimeException('failed'),
        ));

        $this->assertSame('NexusOperationStarted', $result['command']);
        $this->assertInstanceOf(\stdClass::class, $result['options']);
        $this->assertArrayNotHasKey('payloads', $result);

        $failure = new Failure();
        $failure->mergeFromString(\base64_decode($result['failure'], true));
        $this->assertSame('failed', $failure->getMessage());
    }
}
