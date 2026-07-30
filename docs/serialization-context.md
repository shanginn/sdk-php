# Serialization context

Context-aware payload converters can select encryption keys, tenants, or storage
policies from the Temporal namespace and execution that owns a payload. Existing
converters remain compatible: the SDK only supplies context to converters that
implement `SerializationContextAwareInterface`.

```php
use Temporal\DataConverter\ActivitySerializationContext;
use Temporal\DataConverter\PayloadConverterInterface;
use Temporal\DataConverter\SerializationContext;
use Temporal\DataConverter\SerializationContextAwareInterface;
use Temporal\DataConverter\WorkflowSerializationContext;

final class TenantPayloadConverter implements
    PayloadConverterInterface,
    SerializationContextAwareInterface
{
    private ?SerializationContext $context = null;

    public function getSerializationContext(): ?SerializationContext
    {
        return $this->context;
    }

    public function withSerializationContext(?SerializationContext $context): static
    {
        $clone = clone $this;
        $clone->context = $context;
        return $clone;
    }

    // Implement getEncodingType(), toPayload(), and fromPayload() as usual.
}
```

The SDK provides one of these immutable context values:

- `WorkflowSerializationContext`: `namespace` and `workflowId`.
- `ActivitySerializationContext`: `namespace`, `activityType`, `taskQueue`,
  optional `workflowId` and `workflowType`, and `isLocal`.

The Workflow fields are nullable for Standalone Activities because they do not
belong to a Workflow Execution.

Serialization runs during Workflow replay. A context-aware converter must
therefore produce the same bytes for the same input and context, and it must
continue to decode payloads written before context-aware conversion was enabled.
