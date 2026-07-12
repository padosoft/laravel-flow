<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use Padosoft\LaravelFlow\Contracts\NodeCacheRepository;
use Padosoft\LaravelFlow\Executor\Attributes\Cacheable;
use Padosoft\LaravelFlow\Executor\Attributes\Cost;
use Padosoft\LaravelFlow\Executor\Attributes\Retry;
use Padosoft\LaravelFlow\Executor\DryRun\CostEstimate;
use Padosoft\LaravelFlow\Executor\DryRun\DryRunPlanner;
use Padosoft\LaravelFlow\Executor\DryRun\ExecutionPlan;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\GraphRunResult;
use Padosoft\LaravelFlow\Executor\GraphSaga;
use Padosoft\LaravelFlow\Executor\GraphSagaReport;
use Padosoft\LaravelFlow\Executor\InputRouter;
use Padosoft\LaravelFlow\Executor\NodeCache;
use Padosoft\LaravelFlow\Executor\NodeCacheHit;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\NodeResolver;
use Padosoft\LaravelFlow\Executor\Nodes\ApprovalGateNode;
use Padosoft\LaravelFlow\Executor\Nodes\MergeNode;
use Padosoft\LaravelFlow\Executor\ReadinessDecision;
use Padosoft\LaravelFlow\Executor\ReadinessResolver;
use Padosoft\LaravelFlow\Executor\ResolvedNode;
use Padosoft\LaravelFlow\Executor\RetryPolicy;
use Padosoft\LaravelFlow\Executor\RoutedInputs;
use Padosoft\LaravelFlow\Executor\State\IllegalStateTransitionException;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Node\CompensatableNode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExecutorApiContractTest extends TestCase
{
    public function test_node_state_case_values_are_pinned(): void
    {
        $this->assertSame('pending', NodeState::Pending->value);
        $this->assertSame('running', NodeState::Running->value);
        $this->assertSame('paused', NodeState::Paused->value);
        $this->assertSame('succeeded', NodeState::Succeeded->value);
        $this->assertSame('failed', NodeState::Failed->value);
        $this->assertSame('skipped', NodeState::Skipped->value);
        $this->assertSame('blocked', NodeState::Blocked->value);
        $this->assertSame('invalid_input', NodeState::InvalidInput->value);
        $this->assertSame('dead_letter', NodeState::DeadLetter->value);

        $this->assertSame(
            ['pending', 'running', 'paused', 'succeeded', 'failed', 'skipped', 'blocked', 'invalid_input', 'dead_letter'],
            array_map(static fn (NodeState $c): string => $c->value, NodeState::cases()),
        );
    }

    public function test_run_state_case_values_are_pinned(): void
    {
        $this->assertSame('pending', RunState::Pending->value);
        $this->assertSame('running', RunState::Running->value);
        $this->assertSame('paused', RunState::Paused->value);
        $this->assertSame('succeeded', RunState::Succeeded->value);
        $this->assertSame('partially_succeeded', RunState::PartiallySucceeded->value);
        $this->assertSame('failed', RunState::Failed->value);
        $this->assertSame('compensated', RunState::Compensated->value);
        $this->assertSame('aborted', RunState::Aborted->value);
        $this->assertSame('dead_letter', RunState::DeadLetter->value);

        $this->assertSame(
            ['pending', 'running', 'paused', 'succeeded', 'partially_succeeded', 'failed', 'compensated', 'aborted', 'dead_letter'],
            array_map(static fn (RunState $c): string => $c->value, RunState::cases()),
        );
    }

    public function test_executor_api_classes_are_annotated_api(): void
    {
        $classes = [
            NodeState::class,
            RunState::class,
            IllegalStateTransitionException::class,
            ReadinessResolver::class,
            ReadinessDecision::class,
            InputRouter::class,
            RoutedInputs::class,
            MergeNode::class,
            NodeResolver::class,
            ResolvedNode::class,
            NodeExecutor::class,
            GraphRunner::class,
            GraphRunResult::class,
            Retry::class,
            RetryPolicy::class,
            Cacheable::class,
            NodeCache::class,
            NodeCacheHit::class,
            NodeCacheRepository::class,
            GraphSaga::class,
            GraphSagaReport::class,
            CompensatableNode::class,
            Cost::class,
            DryRunPlanner::class,
            ExecutionPlan::class,
            CostEstimate::class,
            ApprovalGateNode::class,
        ];

        foreach ($classes as $class) {
            $doc = (string) (new ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class);
            $this->assertStringNotContainsString('@internal', $doc, $class);
        }
    }

    public function test_graph_saga_surface_is_pinned(): void
    {
        $this->assertSame('reverse-order', GraphSaga::STRATEGY_REVERSE_ORDER);
        $this->assertSame('parallel', GraphSaga::STRATEGY_PARALLEL);
        $this->assertSame('@aggregate', GraphSaga::AGGREGATE_KEY);
        $this->assertTrue((new ReflectionClass(GraphSaga::class))->hasMethod('compensate'));

        $this->assertTrue((new ReflectionClass(CompensatableNode::class))->hasMethod('compensate'));

        foreach (['attempted', 'fullySucceeded'] as $method) {
            $this->assertTrue((new ReflectionClass(GraphSagaReport::class))->hasMethod($method), $method);
        }
    }

    public function test_dry_run_planner_surface_is_pinned(): void
    {
        $this->assertTrue((new ReflectionClass(DryRunPlanner::class))->hasMethod('plan'));

        foreach (['waves', 'skipped'] as $property) {
            $this->assertTrue((new ReflectionClass(ExecutionPlan::class))->hasProperty($property), $property);
        }

        foreach (['perNode', 'total'] as $property) {
            $this->assertTrue((new ReflectionClass(CostEstimate::class))->hasProperty($property), $property);
        }

        $this->assertTrue((new ReflectionClass(Cost::class))->hasProperty('estimate'));
    }

    public function test_state_transition_guards_are_pinned(): void
    {
        foreach (['isTerminal', 'canTransitionTo', 'transitionTo'] as $method) {
            $this->assertTrue((new ReflectionClass(NodeState::class))->hasMethod($method), $method);
            $this->assertTrue((new ReflectionClass(RunState::class))->hasMethod($method), $method);
        }
    }
}
