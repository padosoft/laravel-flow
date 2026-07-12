<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\SecretFailingNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class NodeErrorMessageRedactionTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.persistence.redaction.enabled', true);
        $app['config']->set('laravel-flow.persistence.redaction.keys', ['token', 'apiKey', 'api-key']);
        $app['config']->set('laravel-flow.nodes.handlers', [SecretFailingNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    public function test_a_failed_graph_node_persists_a_redacted_error_message(): void
    {
        // Same secret shapes v1's SecretFailsHandler stub exercises (bare
        // key=value, Bearer token, quoted-JSON key:value) — proves NodeExecutor
        // sanitizes a handler's raw exception message before persisting it,
        // exactly like v1's safeErrorMessage(), not a carve-out for the graph
        // engine's own free-form exception text.
        $graph = new GraphDefinition([new GraphNode('n', 'test.secretfail')], []);

        $result = $this->runner()->run($graph, []);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'n')->first();
        $this->assertNotNull($row);

        $message = (string) $row->error_message;
        $this->assertStringNotContainsString('plain-secret', $message);
        $this->assertStringNotContainsString('camel-secret', $message);
        $this->assertStringNotContainsString('auth-secret', $message);
        $this->assertStringNotContainsString('json-dash-secret', $message);
        $this->assertStringContainsString('[redacted]', $message);
    }

    public function test_a_resolver_failure_persists_a_redacted_error_message(): void
    {
        // The resolver-failure path (unknown/unbound handler) is a SEPARATE
        // persist() call site from the handler-failure path above — must be
        // redacted too, not just the common case.
        $graph = new GraphDefinition(
            [new GraphNode('l', FlowDefinition::LEGACY_NODE_TYPE, ['handler' => 'App\\Handlers\\Missing\\token=plain-secret'])],
            [],
        );

        $result = $this->runner()->run($graph, []);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'l')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('plain-secret', (string) $row->error_message);
    }

    public function test_redaction_is_inert_when_persistence_is_disabled(): void
    {
        // Nothing to protect: persist() writes zero rows when $store is null,
        // so the redaction wiring itself must not be REQUIRED for the executor
        // to keep working without persistence.
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $graph = new GraphDefinition([new GraphNode('n', 'test.secretfail')], []);

        $this->app->make(FlowEngine::class)->dryRunGraph($graph, []);

        $this->assertSame(0, DB::table('flow_run_nodes')->count());
    }
}
