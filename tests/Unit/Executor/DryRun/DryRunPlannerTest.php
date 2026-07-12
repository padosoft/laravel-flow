<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\DryRun;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Executor\DryRun\DryRunPlanner;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CostedNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class DryRunPlannerTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [
            CostedNode::class,
            QueueProbeNode::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
    }

    private function planner(): DryRunPlanner
    {
        return $this->app->make(DryRunPlanner::class);
    }

    private function diamond(): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('a', 'test.costed'),
                new GraphNode('b', 'test.costed'),
                new GraphNode('c', 'test.costed'),
                new GraphNode('d', 'test.costed'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
                new Connection('b', 'out', 'd', 'in'),
                new Connection('c', 'out', 'd', 'in'),
            ],
        );
    }

    public function test_diamond_planned_in_three_waves(): void
    {
        ['plan' => $plan] = $this->planner()->plan($this->diamond(), []);

        $this->assertCount(3, $plan->waves);
        $this->assertSame(['a'], $plan->waves[0]);
        $this->assertEqualsCanonicalizing(['b', 'c'], $plan->waves[1]);
        $this->assertSame(['d'], $plan->waves[2]);
    }

    public function test_cost_estimate_sums_node_hints(): void
    {
        // Two costed nodes + one probe without a #[Cost] hint: only the costed
        // nodes appear per-node, and every dimension sums across them.
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.costed'),
                new GraphNode('p', 'test.probe'),
                new GraphNode('b', 'test.costed'),
            ],
            [
                new Connection('a', 'out', 'p', 'in'),
                new Connection('p', 'out', 'b', 'in'),
            ],
        );

        ['cost' => $cost] = $this->planner()->plan($graph, []);

        $this->assertSame(['tokens' => 100, 'cents' => 2], $cost->perNode['a']);
        $this->assertSame(['tokens' => 100, 'cents' => 2], $cost->perNode['b']);
        $this->assertArrayNotHasKey('p', $cost->perNode, 'a node without a hint contributes nothing');
        $this->assertSame(['tokens' => 200, 'cents' => 4], $cost->total);
    }

    public function test_dry_run_plan_writes_nothing(): void
    {
        $this->planner()->plan($this->diamond(), ['seed' => 1]);

        // ZERO rows across EVERY persistence table — first-class deliverable.
        foreach (['flow_runs', 'flow_run_nodes', 'flow_node_cache', 'flow_node_children', 'flow_audit'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "a dry-run plan writes no {$table} rows");
        }
    }

    public function test_plan_is_the_optimistic_full_set_with_no_skipped_nodes(): void
    {
        // Whether a node self-skips is only knowable at run time (each handler
        // decides its own dry-run behavior), so the planner is OPTIMISTIC:
        // every node lands in a wave and `skipped` stays empty.
        ['plan' => $plan] = $this->planner()->plan($this->diamond(), []);

        $this->assertSame([], $plan->skipped);
        $this->assertEqualsCanonicalizing(
            ['a', 'b', 'c', 'd'],
            array_merge(...$plan->waves),
            'every node is planned into a wave',
        );
    }

    public function test_unresolvable_node_contributes_no_cost_and_still_plans(): void
    {
        // The planner is advisory: an unknown node type must not abort the
        // plan — the node lands in its wave and simply advertises no cost.
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.costed'),
                new GraphNode('x', 'test.unregistered'),
            ],
            [new Connection('a', 'out', 'x', 'in')],
        );

        ['plan' => $plan, 'cost' => $cost] = $this->planner()->plan($graph, []);

        $this->assertSame([['a'], ['x']], $plan->waves);
        $this->assertArrayNotHasKey('x', $cost->perNode);
        $this->assertSame(['tokens' => 100, 'cents' => 2], $cost->total);
    }

    public function test_cost_hint_is_reflected_onto_the_node_definition(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('test.costed');

        $this->assertNotNull($definition->cost);
        $this->assertSame(['tokens' => 100, 'cents' => 2], $definition->cost->estimate);
        $this->assertSame(['estimate' => ['tokens' => 100, 'cents' => 2]], $definition->toArray()['cost']);
    }
}
