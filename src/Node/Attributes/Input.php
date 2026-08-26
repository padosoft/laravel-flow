<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares a typed input port on a public handler property.
 * `$key` defaults to the property name (snake_case is NOT applied).
 *
 * `$requiresTrusted` marks this port a SINK: a graph that wires untrusted
 * data into it is rejected at validation time, before it can be published
 * or run. Put it on the ports where attacker-chosen bytes would become
 * attacker-chosen behaviour — a shell command, a tool name, a tool's
 * argument map, a URL that will be fetched with credentials attached.
 *
 * `$multiple` marks a fan-in (variadic) port: the executor coalesces every
 * wire into it as an ordered `list<mixed>` (each element validated against
 * `$type`) instead of rejecting the second wire. Only `PortType::Json` /
 * `PortType::Any` ports may be `multiple`, and the handler property must be
 * `array`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Input
{
    public function __construct(
        public readonly PortType $type,
        public readonly bool $required = false,
        public readonly ?string $label = null,
        public readonly ?string $key = null,
        public readonly bool $multiple = false,
        public readonly bool $requiresTrusted = false,
    ) {}
}
