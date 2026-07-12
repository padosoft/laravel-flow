<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Closure;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\GraphSaga;
use Padosoft\LaravelFlow\Executor\NodeResolver;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CompensatableRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CompensationThrowingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\RecordingAggregateCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;

final class GraphSagaTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [
            CompensatableRecordingNode::class,
            CompensationThrowingNode::class,
            FailingGraphNode::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        CompensatableRecordingNode::reset();
        RecordingAggregateCompensator::reset();
        RecordingCompensator::reset();
        AlwaysSucceedsHandler::$callCount = 0;
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    public function test_only_completed_nodes_compensate(): void
    {
        // a succeeds, b fails, c never runs (blocked): ONLY a compensates.
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('b', 'test.fail'),
                new GraphNode('c', 'test.saga.comp'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('b', 'out', 'c', 'in'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(['a'], CompensatableRecordingNode::$log, 'only the completed node compensates');
        $this->assertSame(RunState::Compensated, $result->state, 'full rollback flips the run to compensated');

        $run = DB::table('flow_runs')->where('id', $result->runId)->first();
        $this->assertSame('compensated', $run->status);
        $this->assertTrue((bool) $run->compensated);
        $this->assertSame('succeeded', $run->compensation_status);
    }

    public function test_compensation_context_carries_the_node_outputs(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('b', 'test.fail'),
            ],
            [new Connection('a', 'out', 'b', 'in')],
        );

        $this->runner()->run($graph, []);

        // compensate() receives the node's recorded OUTPUT port map as inputs.
        $this->assertSame(['out' => ['produced_by' => 'a']], CompensatableRecordingNode::$contexts['a']);
    }

    public function test_reverse_topological_order_on_diamond(): void
    {
        // Diamond a -> (b, c) -> d, then e fails. Compensation must run d
        // BEFORE b/c, and b/c BEFORE a (reverse-topological), b/c in any order.
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('b', 'test.saga.comp'),
                new GraphNode('c', 'test.saga.comp'),
                new GraphNode('d', 'test.saga.comp'),
                new GraphNode('e', 'test.fail'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
                new Connection('b', 'out', 'd', 'in'),
                new Connection('c', 'out', 'd', 'in'),
                new Connection('d', 'out', 'e', 'in'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $log = CompensatableRecordingNode::$log;
        $this->assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $log);

        $position = array_flip($log);
        $this->assertTrue($position['d'] < $position['b'], 'd compensates before b');
        $this->assertTrue($position['d'] < $position['c'], 'd compensates before c');
        $this->assertTrue($position['b'] < $position['a'], 'b compensates before a');
        $this->assertTrue($position['c'] < $position['a'], 'c compensates before a');

        $this->assertSame(RunState::Compensated, $result->state);
    }

    public function test_parallel_strategy_batches_independent_compensators(): void
    {
        $driver = new class implements ConcurrencyDriver
        {
            /** @var list<int> */
            public array $batchSizes = [];

            public function run(Closure|array $tasks): array
            {
                $tasks = is_array($tasks) ? $tasks : [$tasks];
                $this->batchSizes[] = count($tasks);

                return array_map(static fn (Closure $task): array => $task(), $tasks);
            }

            public function defer(Closure|array $tasks): DeferredCallback
            {
                throw new \RuntimeException('not used');
            }
        };

        $saga = new GraphSaga($this->app->make(NodeResolver::class), $this->app, $driver);

        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('b', 'test.saga.comp'),
                new GraphNode('c', 'test.saga.comp'),
                new GraphNode('f', 'test.fail'),
            ],
            [
                new Connection('a', 'out', 'f', 'in'),
                new Connection('b', 'out', 'f', 'in'),
                new Connection('c', 'out', 'f', 'in'),
            ],
        );

        $report = $saga->compensate(
            'run-1',
            'graph',
            $graph,
            ['a' => NodeState::Succeeded, 'b' => NodeState::Succeeded, 'c' => NodeState::Succeeded, 'f' => NodeState::Failed],
            ['a' => ['out' => []], 'b' => ['out' => []], 'c' => ['out' => []]],
            GraphSaga::STRATEGY_PARALLEL,
        );

        $this->assertSame([3], $driver->batchSizes, 'all three compensators went through ONE driver batch');
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $report->compensatedNodeIds);
        $this->assertSame([], $report->errors);
        $this->assertTrue($report->fullySucceeded());
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], CompensatableRecordingNode::$log);
    }

    public function test_aggregate_compensator_runs_last(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('b', 'test.saga.comp'),
                new GraphNode('f', 'test.fail'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('b', 'out', 'f', 'in'),
            ],
            ['aggregate_compensator' => RecordingAggregateCompensator::class],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(['b', 'a', '@aggregate'], CompensatableRecordingNode::$log, 'aggregate runs after every per-node compensator');
        $this->assertSame(RunState::Compensated, $result->state);

        // The aggregate receives EVERY succeeded node's outputs keyed by node id.
        $this->assertNotNull(RecordingAggregateCompensator::$received);
        $this->assertArrayHasKey('a', RecordingAggregateCompensator::$received);
        $this->assertArrayHasKey('b', RecordingAggregateCompensator::$received);
    }

    public function test_legacy_node_compensates_via_v1_compensator(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('l1', FlowDefinition::LEGACY_NODE_TYPE, [
                    'handler' => AlwaysSucceedsHandler::class,
                    'compensator' => RecordingCompensator::class,
                ]),
                new GraphNode('f', 'test.fail'),
            ],
            [new Connection('l1', 'output', 'f', 'in')],
        );

        $result = $this->runner()->run($graph, ['seed' => 1]);

        $this->assertCount(1, RecordingCompensator::$invocations, 'the v1 compensator ran once');
        $invocation = RecordingCompensator::$invocations[0];
        $this->assertSame($result->runId, $invocation['flowRunId']);
        // The compensator receives the step's result rebuilt from the adapted
        // node's `output` port — exactly what the v1 handler produced.
        $this->assertSame(AlwaysSucceedsHandler::class, $invocation['originalOutput']['handler']);

        $run = DB::table('flow_runs')->where('id', $result->runId)->first();
        $this->assertSame('compensated', $run->status);
        $this->assertSame('succeeded', $run->compensation_status);
    }

    public function test_partial_compensation_failure_keeps_the_failure_state(): void
    {
        // a and m succeed, f fails. m's compensator THROWS: the saga keeps
        // rolling back (a still compensates), but the run is NOT marked
        // compensated — it keeps its failure state with compensation_status
        // 'failed' so the operator sees the gap.
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('m', 'test.saga.compthrow'),
                new GraphNode('f', 'test.fail'),
            ],
            [
                new Connection('a', 'out', 'm', 'in'),
                new Connection('m', 'out', 'f', 'in'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(['a'], CompensatableRecordingNode::$log, 'the throwing compensator did not abort the remaining rollback');
        $this->assertSame(RunState::PartiallySucceeded, $result->state, 'a partial rollback keeps the failure state');

        $run = DB::table('flow_runs')->where('id', $result->runId)->first();
        $this->assertSame('partially_succeeded', $run->status);
        $this->assertFalse((bool) $run->compensated);
        $this->assertSame('failed', $run->compensation_status);
    }

    public function test_run_without_compensators_keeps_its_failure_state_untouched(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('f', 'test.fail')],
            [],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(RunState::Failed, $result->state);

        $run = DB::table('flow_runs')->where('id', $result->runId)->first();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->compensation_status, 'no compensation was attempted, none is recorded');
    }

    public function test_dry_run_never_compensates(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('f', 'test.fail'),
            ],
            [new Connection('a', 'out', 'f', 'in')],
        );

        $this->app->make(FlowEngine::class)->dryRunGraph($graph, []);

        $this->assertSame([], CompensatableRecordingNode::$log, 'compensation is a real side effect: never on a dry run');
    }

    public function test_unsupported_compensation_strategy_fails_fast_at_resolution(): void
    {
        // A bad config must surface on the FIRST graph run (container
        // resolution), not mid-failure after nodes already ran — mirroring
        // v1's early rejection of an unsupported compensation_strategy.
        $this->app['config']->set('laravel-flow.compensation_strategy', 'bogus');

        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('Unsupported compensation strategy [bogus]');

        $this->app->make(GraphRunner::class);
    }

    public function test_queued_run_compensates_on_failure(): void
    {
        $this->app['config']->set('queue.default', 'sync');

        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('b', 'test.saga.comp'),
                new GraphNode('f', 'test.fail'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('b', 'out', 'f', 'in'),
            ],
        );

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);

        $this->assertSame(['b', 'a'], CompensatableRecordingNode::$log, 'queued finalize triggers the same reverse-order saga');

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('compensated', $run->status);
        $this->assertTrue((bool) $run->compensated);
        $this->assertSame('succeeded', $run->compensation_status);
    }
}
