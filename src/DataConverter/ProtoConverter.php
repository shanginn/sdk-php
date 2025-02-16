<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\DataConverter;

use Google\Protobuf\Internal\Message;
use Temporal\Api\Common\V1\Payload;
use Temporal\Exception\DataConverterException;

class ProtoConverter extends Converter
{
    public function getEncodingType(): string
    {
        return EncodingKeys::METADATA_ENCODING_PROTOBUF;
    }

    /**
     * @param mixed $value
     */
    public function toPayload($value): ?Payload
    {
        if (!$value instanceof Message) {
            return null;
        }

        $payload = $this->create($value->serializeToString());

        $payload->setMetadata([
            EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType(),
            EncodingKeys::METADATA_MESSAGE_TYPE => \Google\Protobuf\Internal\DescriptorPool::getGeneratedPool()
                ->getDescriptorByClassName($value::class)
                ?->getFullName(),
        ]);

        return $payload;
    }

    /**
     * @return Message
     * @throws DataConverterException
     */
    public function fromPayload(Payload $payload, Type $type)
    {
        if (!$type->isClass()) {
            throw new DataConverterException('Unable to decode value using protobuf converter');
        }

        try {
            $obj = new \ReflectionClass($type->getName());
        } catch (\ReflectionException $e) {
            throw new DataConverterException($e->getMessage(), $e->getCode(), $e);
        }

        /** @var Message $instance */
        $instance = $obj->newInstance();
        $instance->mergeFromString($payload->getData());

        return $instance;
    }
}
