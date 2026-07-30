<?php

declare(strict_types=1);

namespace Temporal\Tests\Unit\Client\Activity;

use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\ActivitySerializationContext;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\SerializationContext;
use Temporal\DataConverter\SerializationContextAwareInterface;

final class StandaloneActivitySigningDataConverter implements DataConverterInterface, SerializationContextAwareInterface
{
    private const SIGNATURE_KEY = 'standalone-activity-context';

    private readonly DataConverterInterface $delegate;
    private ?SerializationContext $context = null;

    public function __construct()
    {
        $this->delegate = DataConverter::createDefault();
    }

    public function withSerializationContext(?SerializationContext $context): static
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    public function getSerializationContext(): ?SerializationContext
    {
        return $this->context;
    }

    public function fromPayload(Payload $payload, mixed $type): mixed
    {
        $metadata = $payload->getMetadata();
        $actual = $metadata[self::SIGNATURE_KEY]
            ?? '';
        $expected = $this->signature();
        if ($actual !== $expected) {
            throw new \RuntimeException(
                \sprintf('Standalone Activity context mismatch: expected "%s", got "%s".', $expected, $actual),
            );
        }

        return $this->delegate->fromPayload($payload, $type);
    }

    public function toPayload(mixed $value): Payload
    {
        $payload = clone $this->delegate->toPayload($value);
        $metadata = \iterator_to_array($payload->getMetadata());
        $metadata[self::SIGNATURE_KEY] = $this->signature();
        $payload->setMetadata($metadata);

        return $payload;
    }

    private function signature(): string
    {
        if (!$this->context instanceof ActivitySerializationContext) {
            return '';
        }

        return \implode('|', [
            $this->context->namespace,
            $this->context->activityType,
            $this->context->taskQueue,
            $this->context->workflowId ?? '',
            $this->context->workflowType ?? '',
            $this->context->isLocal ? 'local' : 'remote',
        ]);
    }
}
