<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use InvalidArgumentException;

/**
 * Immutable definition of one input or output port of a node.
 *
 * `$propertyName` is the handler property the port hydrates into; it is a
 * reflection detail and is deliberately excluded from {@see toArray()}.
 *
 * Keys starting with `_` are reserved for engine-level buckets (for
 * example the validator's `_unknown` violations group) and are rejected.
 *
 * `$provenance` is meaningful on OUTPUT ports and `$requiresTrusted` on
 * INPUT ports; each is inert on the other side. They are separate fields
 * rather than one because they answer different questions — "what does
 * this port emit?" versus "what will this port accept?" — and a port is
 * only ever one of the two.
 *
 * @api
 */
final class PortDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly PortType $type,
        public readonly bool $required = false,
        public readonly ?string $label = null,
        public readonly ?string $propertyName = null,
        public readonly bool $multiple = false,
        public readonly PortProvenance $provenance = PortProvenance::Derived,
        public readonly bool $requiresTrusted = false,
    ) {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException('Port key must not be empty.');
        }

        if (str_starts_with($this->key, '_')) {
            throw new InvalidArgumentException('Port keys starting with "_" are reserved.');
        }
    }

    /**
     * @return array{key: string, type: string, required: bool, label: string, multiple: bool, provenance: string, requires_trusted: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'required' => $this->required,
            'label' => $this->label ?? $this->key,
            'multiple' => $this->multiple,
            'provenance' => $this->provenance->value,
            'requires_trusted' => $this->requiresTrusted,
        ];
    }
}
