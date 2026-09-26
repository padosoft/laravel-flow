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

    /**
     * c --yes--> a --\
     *   \--no--> b ---> m
     */
    private function branching(): GraphDefinition
    {
        return new GraphDefinition(
            [new GraphNode('c', 't.c'), new GraphNode('a', 't.a'), new GraphNode('b', 't.b'), new GraphNode('m', 't.m')],
            [
                new Connection('c', 'yes', 'a', 'in'),
                new Connection('c', 'no', 'b', 'in'),
                new Connection('a', 'out', 'm', 'x'),
                new Connection('b', 'out', 'm', 'y'),
            ],
        );
    }

    public function test_an_empty_active_ports_map_reproduces_the_historical_decision(): void
    {
        $states = ['a' => NodeState::Succeeded];

        $this->assertEquals(
            (new ReadinessResolver)->resolve($this->diamond(), $states),
            (new ReadinessResolver)->resolve($this->diamond(), $states, []),
        );
        $this->assertSame([], (new ReadinessResolver)->resolve($this->diamond(), $states)->skipped);
    }

    public function test_a_dead_port_skips_its_target_and_readies_the_live_one(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->branching(), ['c' => NodeState::Succeeded], ['c' => ['yes']]);

        $this->assertSame(['b'], $decision->skipped);
        $this->assertSame(['a'], $decision->ready);
        $this->assertSame([], $decision->blocked);
        // b is skipped by THIS pass but not persisted yet, so the graph is not terminal.
        $this->assertFalse($decision->allTerminal);
    }

    public function test_skipping_propagates_transitively_within_a_single_pass(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('c', 't.c'), new GraphNode('x', 't.x'), new GraphNode('y', 't.y'), new GraphNode('z', 't.z')],
            [
                new Connection('c', 'no', 'x', 'in'),
                new Connection('x', 'out', 'y', 'in'),
                new Connection('y', 'out', 'z', 'in'),
            ],
        );

        $decision = (new ReadinessResolver)->resolve($graph, ['c' => NodeState::Succeeded], ['c' => ['yes']]);

        $this->assertSame(['x', 'y', 'z'], $decision->skipped);
        $this->assertSame([], $decision->ready);
    }

    public function test_a_node_with_a_live_and_a_dead_wire_still_runs(): void
    {
        $states = ['c' => NodeState::Succeeded, 'a' => NodeState::Succeeded, 'b' => NodeState::Skipped];
        $decision = (new ReadinessResolver)->resolve($this->branching(), $states, ['c' => ['yes'], 'b' => []]);

        $this->assertSame(['m'], $decision->ready);
        $this->assertSame([], $decision->skipped);
    }

    public function test_a_poisoned_predecessor_wins_over_a_dead_wire(): void
    {
        $states = ['c' => NodeState::Succeeded, 'a' => NodeState::Failed, 'b' => NodeState::Skipped];
        $decision = (new ReadinessResolver)->resolve($this->branching(), $states, ['c' => ['yes'], 'b' => []]);

        $this->assertSame(['m'], $decision->blocked);
        $this->assertSame([], $decision->skipped);
    }

    public function test_a_skipped_node_without_a_recorded_list_stays_live(): void
    {
        // A dry-run / cancel skip carries no active_ports, so its wires are live.
        $decision = (new ReadinessResolver)->resolve($this->linearChain(), ['a' => NodeState::Skipped]);

        $this->assertSame(['b'], $decision->ready);
        $this->assertSame([], $decision->skipped);
    }

    public function test_a_pending_source_never_kills_its_wire(): void
    {
        $decision = (new ReadinessResolver)->resolve($this->branching(), [], ['c' => ['yes']]);

        $this->assertSame(['c'], $decision->ready);
        $this->assertSame([], $decision->skipped);
    }

    public function test_two_wires_from_one_source_are_judged_per_port(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('c', 't.c'), new GraphNode('t', 't.t')],
            [new Connection('c', 'yes', 't', 'p'), new Connection('c', 'no', 't', 'q')],
        );

        $decision = (new ReadinessResolver)->resolve($graph, ['c' => NodeState::Succeeded], ['c' => ['no']]);

        $this->assertSame(['t'], $decision->ready);
        $this->assertSame([], $decision->skipped);
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
