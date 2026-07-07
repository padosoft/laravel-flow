<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;

/**
 * Marks a class as a flow node handler and declares its catalog identity.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FlowNode
{
    public function __construct(
        public readonly string $type,
        public readonly string $category = 'general',
        public readonly ?string $name = null,
        public readonly ?string $icon = null,
        public readonly ?string $description = null,
    ) {}
}
