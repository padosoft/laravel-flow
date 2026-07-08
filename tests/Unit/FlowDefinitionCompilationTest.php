<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\ApprovalGate;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;

final class FlowDefinitionCompilationTest extends TestCase
{
    public function test_multi_step_definition_compiles_to_an_ordered_path_graph_with_config_fidelity(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.compile')
            ->withInput(['a', 'b'])
            ->step('first', AlwaysSucceedsHandler::class)
            ->step('second', SecondHandler::class)
            ->withDryRun(true)
            ->compensateWith(FirstStepCompensator::class)
            ->approvalGate('manager')
            ->withAggregateCompensator(FirstStepCompensator::class)
            ->register();

        $graph = $engine->definition('flow.compile')->toGraphDefinition();

        $this->assertSame(['first', 'second', 'manager'], $graph->nodeIds());
        $this->assertSame(['first', 'second', 'manager'], $graph->topologicalOrder());

        foreach ($graph->nodeIds() as $id) {
            $node = $graph->node($id);
            $this->assertNotNull($node);
            $this->assertSame(FlowDefinition::LEGACY_NODE_TYPE, $node->type);
        }

        $first = $graph->node('first');
        $this->assertNotNull($first);
        $this->assertSame([
            'handler' => AlwaysSucceedsHandler::class,
            'supports_dry_run' => false,
            'compensator' => null,
            'approval_gate' => false,
        ], $first->config);

        $second = $graph->node('second');
        $this->assertNotNull($second);
        $this->assertSame([
            'handler' => SecondHandler::class,
            'supports_dry_run' => true,
            'compensator' => FirstStepCompensator::class,
            'approval_gate' => false,
        ], $second->config);

        $manager = $graph->node('manager');
        $this->assertNotNull($manager);
        $this->assertSame([
            'handler' => ApprovalGate::class,
            'supports_dry_run' => true,
            'compensator' => null,
            'approval_gate' => true,
        ], $manager->config);

        $this->assertCount(2, $graph->connections);
        $this->assertSame('first.output>second.input', $graph->connections[0]->identity());
        $this->assertSame('second.output>manager.input', $graph->connections[1]->identity());

        $this->assertSame([
            'required_inputs' => ['a', 'b'],
            'aggregate_compensator' => FirstStepCompensator::class,
            'compiled_from' => 'v1-builder',
        ], $graph->metadata);
    }

    public function test_single_step_definition_compiles_to_one_node_and_zero_connections(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.single')
            ->step('only', AlwaysSucceedsHandler::class)
            ->register();

        $graph = $engine->definition('flow.single')->toGraphDefinition();

        $this->assertSame(['only'], $graph->nodeIds());
        $this->assertCount(0, $graph->connections);
    }

    public function test_checksum_is_stable_across_repeated_compilations(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.checksum')
            ->step('first', AlwaysSucceedsHandler::class)
            ->step('second', SecondHandler::class)
            ->register();

        $definition = $engine->definition('flow.checksum');
        $serializer = new GraphSerializer;

        $checksumA = $serializer->checksum($definition->toGraphDefinition());
        $checksumB = $serializer->checksum($definition->toGraphDefinition());

        $this->assertSame($checksumA, $checksumB);
    }

    public function test_graph_serializer_round_trips_the_compiled_graph_with_a_stable_checksum(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.roundtrip')
            ->step('first', AlwaysSucceedsHandler::class)
            ->step('second', SecondHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->approvalGate('manager')
            ->register();

        $graph = $engine->definition('flow.roundtrip')->toGraphDefinition();
        $serializer = new GraphSerializer;

        $json = $serializer->toJson($graph);
        $rehydrated = $serializer->fromJson($json);

        $this->assertSame($serializer->checksum($graph), $serializer->checksum($rehydrated));
        $this->assertSame($graph->nodeIds(), $rehydrated->nodeIds());
        $this->assertSame($graph->topologicalOrder(), $rehydrated->topologicalOrder());
    }

    public function test_compiling_a_zero_step_definition_throws_invalid_graph_exception(): void
    {
        $definition = new FlowDefinition('flow.empty', [], []);

        $this->expectException(InvalidGraphException::class);

        $definition->toGraphDefinition();
    }
}
