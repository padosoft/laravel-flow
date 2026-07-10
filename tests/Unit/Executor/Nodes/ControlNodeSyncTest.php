<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\Nodes;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\DoubleNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * Synchronous-executor coverage of the sub-flow / fan-out control nodes: a
 * published child flow is run inline once (sub-flow) or once per item (fan-out),
 * with the ordered per-child output aggregated into `results`.
 */
final class ControlNodeSyncTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [DoubleNode::class, FailingGraphNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        DoubleNode::$invocations = 0;

        $this->publishChild('doubler', new GraphDefinition([new GraphNode('d', 'test.double')], []));
        $this->publishChild('boom', new GraphDefinition([new GraphNode('b', 'test.fail')], []));
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    private function publishChild(string $name, GraphDefinition $graph): void
    {
        $repository = $this->app->make(DefinitionRepository::class);
        $stored = $repository->createDraft($name, $graph);
        $repository->publish($name, $stored->version);
    }

    public function test_subflow_runs_published_flow_as_child(): void
    {
        $graph = new GraphDefinition([
            new GraphNode('sf', 'flow.subflow', ['flow' => 'doubler', 'input' => ['value' => 5]]),
        ], []);

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['sf']);
        $this->assertSame([['d' => ['doubled' => 10]]], $result->nodeOutputs['sf']['results']);
    }

    public function test_foreach_fans_out_over_list_and_joins_ordered(): void
    {
        $graph = new GraphDefinition([
            new GraphNode('fe', 'flow.foreach', ['flow' => 'doubler', 'items' => [1, 2, 3]]),
        ], []);

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['fe']);
        $this->assertSame([
            ['d' => ['doubled' => 2]],
            ['d' => ['doubled' => 4]],
            ['d' => ['doubled' => 6]],
        ], $result->nodeOutputs['fe']['results']);
    }

    public function test_map_concurrency_cap_respected(): void
    {
        $graph = new GraphDefinition([
            new GraphNode('m', 'flow.map', ['flow' => 'doubler', 'maxConcurrency' => 2, 'items' => [1, 2, 3, 4]]),
        ], []);

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['m']);
        $this->assertSame([
            ['d' => ['doubled' => 2]],
            ['d' => ['doubled' => 4]],
            ['d' => ['doubled' => 6]],
            ['d' => ['doubled' => 8]],
        ], $result->nodeOutputs['m']['results'], 'batched execution preserves ordering and runs every item');
        $this->assertSame(4, DoubleNode::$invocations);
    }

    public function test_child_failure_propagates_to_parent(): void
    {
        $graph = new GraphDefinition([
            new GraphNode('sf', 'flow.subflow', ['flow' => 'boom', 'input' => []]),
        ], []);

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Failed, $result->nodeStates['sf']);
        $this->assertSame(RunState::Failed, $result->state);
    }
}
