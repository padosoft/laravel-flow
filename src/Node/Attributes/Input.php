<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares a typed input port on a public handler property.
 * `$key` defaults to the property name (snake_case is NOT applied).
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
    ) {}
}
