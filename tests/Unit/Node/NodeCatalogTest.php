<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeCatalog;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

final class NodeCatalogTest extends TestCase
{
    public function test_catalog_shape(): void
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->registerMany([UpperNode::class, GreetNode::class]);

        $catalog = (new NodeCatalog($registry))->toArray();

        $this->assertSame(NodeCatalog::SCHEMA_VERSION, $catalog['schema_version']);
        $this->assertSame(['test.greet', 'test.upper'], array_column($catalog['nodes'], 'type'));
        $this->assertSame('text', $catalog['nodes'][0]['inputs'][0]['type']);

        $decoded = json_decode((new NodeCatalog($registry))->toJson(), true);
        $this->assertSame($catalog, $decoded);
    }
}
