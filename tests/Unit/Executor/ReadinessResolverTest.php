<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Padosoft\LaravelFlow\Executor\ReadinessResolver;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use PHPUnit\Framework\TestCase;

final class ReadinessResolverTest extends TestCase
{
    private function linearChain(): GraphDefinition
    {
        return new GraphDefinition(
            [new GraphNode('a', 't.a'), new GraphNode('b', 't.b'), new GraphNode('c', 't.c')],
            [new Connection('a', 'out', 'b', 'in'), new Connection('b', 'out', 'c', 'in')],
        );
    }

    private function diamond(): GraphDefinition
    {
        return new GraphDefinition(
            [new GraphNode('a', 't.a'), new GraphNode('b', 't.b'), new GraphNode('c', 't.c'), new GraphNode('d', 't.d')],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
                new Connection('b', 'out', 'd', 'x'),
                new Connection('c', 'out', 'd', 'y'),
            ],
        );
    }

    public function test_linear_chain_readies_one_at_a_time(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->linearChain(), []);

        $this->assertSame(['a'], $decision->ready);
        $this->assertSame([], $decision->blocked);
        $this->assertFalse($decision->allTerminal);

        $next = (new ReadinessResolver)->resolve($this->linearChain(), ['a' => NodeState::Succeeded]);
        $this->assertSame(['b'], $next->ready);
    }

    public function test_diamond_readies_parallel_wave(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->diamond(), ['a' => NodeState::Succeeded]);

        $this->assertSame(['b', 'c'], $decision->ready);
        $this->assertSame([], $decision->blocked);
    }

    public function test_failed_upstream_blocks_not_pends(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->linearChain(), ['a' => NodeState::Failed]);

        $this->assertSame([], $decision->ready);
        $this->assertSame(['b'], $decision->blocked);
    }

    public function test_skipped_upstream_still_readies_downstream(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->linearChain(), ['a' => NodeState::Skipped]);

        $this->assertSame(['b'], $decision->ready);
        $this->assertSame([], $decision->blocked);
    }

    public function test_mixed_predecessors_block_when_any_failed(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->diamond(), [
            'a' => NodeState::Succeeded,
            'b' => NodeState::Succeeded,
            'c' => NodeState::Failed,
        ]);

        $this->assertSame([], $decision->ready);
        $this->assertSame(['d'], $decision->blocked);
    }

    public function test_running_predecessor_neither_readies_nor_blocks(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->linearChain(), ['a' => NodeState::Running]);

        $this->assertSame([], $decision->ready);
        $this->assertSame([], $decision->blocked);
    }

    public function test_all_terminal_detection(): void
    {
        $notTerminal = (new ReadinessResolver)->resolve($this->linearChain(), [
            'a' => NodeState::Succeeded,
            'b' => NodeState::Succeeded,
        ]);
        $this->assertFalse($notTerminal->allTerminal);

        $terminal = (new ReadinessResolver)->resolve($this->linearChain(), [
            'a' => NodeState::Succeeded,
            'b' => NodeState::Succeeded,
            'c' => NodeState::Succeeded,
        ]);
        $this->assertTrue($terminal->allTerminal);

        $blockedTerminal = (new ReadinessResolver)->resolve($this->linearChain(), [
            'a' => NodeState::Failed,
            'b' => NodeState::Blocked,
            'c' => NodeState::Blocked,
        ]);
        $this->assertTrue($blockedTerminal->allTerminal);
    }
}
