<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;

/**
 * Deliberately invalid: the empty type is rejected by the FlowNode
 * attribute constructor when the factory calls newInstance().
 */
#[FlowNode(type: '')]
final class EmptyTypeNode {}
