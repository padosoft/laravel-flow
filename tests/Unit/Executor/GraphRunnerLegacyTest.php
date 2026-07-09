<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\OracleStepHandler;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class GraphRunnerLegacyTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
    }

    public function test_legacy_step_runs_inside_graph_via_adapter(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('s', FlowDefinition::LEGACY_NODE_TYPE, ['handler' => OracleStepHandler::class])],
            [],
        );

        $result = $this->app->make(FlowEngine::class)->runGraph($graph, ['tenant' => 'acme'], null, 'flow.legacy');

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['s']);
        $this->assertSame(
            ['handled_by' => OracleStepHandler::class, 'flow' => 'flow.legacy'],
            $result->nodeOutputs['s']['output'],
        );
    }

    public function test_compiled_v1_flow_matches_v1_engine_output(): void
    {
        Flow::define('flow.oracle')
            ->withInput(['tenant'])
            ->step('one', OracleStepHandler::class)
            ->step('two', OracleStepHandler::class)
            ->step('three', OracleStepHandler::class)
            ->register();

        $input = ['tenant' => 'acme'];

        $v1Run = Flow::execute('flow.oracle', $input);

        $graph = Flow::definition('flow.oracle')->toGraphDefinition();
        $graphResult = $this->app->make(FlowEngine::class)->runGraph($graph, $input, null, 'flow.oracle');

        $this->assertSame(RunState::Succeeded, $graphResult->state);

        foreach (['one', 'two', 'three'] as $step) {
            $this->assertSame(NodeState::Succeeded, $graphResult->nodeStates[$step], $step);
            $this->assertSame(
                $v1Run->stepResults[$step]->output,
                $graphResult->nodeOutputs[$step]['output'],
                "step [{$step}] output must match the v1 engine",
            );
        }
    }
}
