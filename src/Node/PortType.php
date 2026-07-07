<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Wire-level type of a node port. Drives connection compatibility in the
 * Studio/graph validator and runtime value validation.
 *
 * Open for extension: add cases (e.g. Money, Binary) together with the
 * first node that needs them.
 *
 * @api
 */
enum PortType: string
{
    case Text = 'text';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Json = 'json';
    case Any = 'any';

    /**
     * Whether a value produced by a `$source`-typed output port may be
     * wired into an input port of this type.
     */
    public function accepts(self $source): bool
    {
        if ($this === self::Any || $source === self::Any) {
            return true;
        }

        if ($this === self::Float && $source === self::Int) {
            return true;
        }

        return $this === $source;
    }

    /**
     * Runtime check that a concrete value conforms to this port type.
     */
    public function validates(mixed $value): bool
    {
        return match ($this) {
            self::Text => is_string($value),
            self::Int => is_int($value),
            self::Float => is_float($value) || is_int($value),
            self::Bool => is_bool($value),
            self::Json => is_array($value),
            self::Any => true,
        };
    }
}
