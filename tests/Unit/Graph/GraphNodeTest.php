<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\GraphNode;
use PHPUnit\Framework\TestCase;

final class GraphNodeTest extends TestCase
{
    public function test_holds_identity_config_and_position(): void
    {
        $node = new GraphNode('n1', 'test.greet', ['prompt' => 'hi'], ['x' => 10, 'y' => 20.5]);

        $this->assertSame('n1', $node->id);
        $this->assertSame('test.greet', $node->type);
        $this->assertSame(['prompt' => 'hi'], $node->config);
        $this->assertSame(['x' => 10, 'y' => 20.5], $node->position);
    }

    public function test_rejects_blank_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/node id/i');

        new GraphNode('  ', 'test.greet');
    }

    public function test_rejects_blank_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/node type/i');

        new GraphNode('n1', '');
    }

    public function test_rejects_malformed_position(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/position/i');

        new GraphNode('n1', 'test.greet', [], ['x' => 'left']);
    }

    public function test_rejects_numeric_string_position(): void
    {
        // The documented shape is int|float: numeric strings are not allowed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/position must carry int\|float/i');

        new GraphNode('n1', 'test.greet', [], ['x' => '10', 'y' => 20]);
    }
}
