<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares a typed output port on a public handler property.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Output
{
    public function __construct(
        public readonly PortType $type,
        public readonly ?string $label = null,
        public readonly ?string $key = null,
    ) {}
}
