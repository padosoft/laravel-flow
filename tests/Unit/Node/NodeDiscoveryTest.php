<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeDiscovery;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

final class NodeDiscoveryTest extends TestCase
{
    public function test_discovers_only_attributed_handler_classes(): void
    {
        $found = (new NodeDiscovery)->discover(
            __DIR__.'/../../Fixtures/Nodes',
            'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes',
        );

        $this->assertSame([GreetNode::class, UpperNode::class], $found);
    }

    public function test_nonexistent_path_returns_empty(): void
    {
        $this->assertSame([], (new NodeDiscovery)->discover(__DIR__.'/nope', 'App\\Nope'));
    }

    public function test_trailing_separator_in_path_is_handled(): void
    {
        $found = (new NodeDiscovery)->discover(
            __DIR__.'/../../Fixtures/Nodes'.DIRECTORY_SEPARATOR,
            'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes',
        );

        $this->assertSame([GreetNode::class, UpperNode::class], $found);
    }
}
