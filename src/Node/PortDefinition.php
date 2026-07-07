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
    ) {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException('Port key must not be empty.');
        }

        if (str_starts_with($this->key, '_')) {
            throw new InvalidArgumentException('Port keys starting with "_" are reserved.');
        }
    }

    /**
     * @return array{key: string, type: string, required: bool, label: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'required' => $this->required,
            'label' => $this->label ?? $this->key,
        ];
    }
}
