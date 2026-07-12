<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\NodeCacheRepository;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\NodeCacheHit;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CacheableEchoNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CacheablePausingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CacheableSecretNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CacheableTtlNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use RuntimeException;

final class NodeCacheTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [
            CacheableEchoNode::class,
            CacheablePausingNode::class,
            CacheableSecretNode::class,
            CacheableTtlNode::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        CacheableEchoNode::$invocations = 0;
        CacheablePausingNode::$invocations = 0;
        CacheableSecretNode::$invocations = 0;
        CacheableTtlNode::$invocations = 0;
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    private function echoGraph(int $value): GraphDefinition
    {
        return new GraphDefinition([new GraphNode('c', 'test.cache.echo', ['value' => $value])], []);
    }

    public function test_miss_then_store_then_hit(): void
    {
        $this->runner()->run($this->echoGraph(5), []);

        $this->assertSame(1, CacheableEchoNode::$invocations, 'first run is a miss: handler runs');
        $this->assertSame(1, DB::table('flow_node_cache')->count(), 'a cache row was stored');

        $this->runner()->run($this->echoGraph(5), []);

        $this->assertSame(1, CacheableEchoNode::$invocations, 'second run is a hit: handler is NOT re-run');
        $this->assertSame(1, DB::table('flow_node_cache')->count(), 'still one cache row');
    }

    public function test_hit_returns_cached_outputs_without_running_handler(): void
    {
        $first = $this->runner()->run($this->echoGraph(7), []);
        $second = $this->runner()->run($this->echoGraph(7), []);

        $this->assertSame(1, CacheableEchoNode::$invocations);
        $this->assertSame(['echoed' => 7], $first->nodeOutputs['c']);
        $this->assertSame($first->nodeOutputs['c'], $second->nodeOutputs['c'], 'hit reproduces the miss output');
    }

    public function test_cache_hit_records_content_hash_on_the_node_run(): void
    {
        $first = $this->runner()->run($this->echoGraph(3), []);
        $second = $this->runner()->run($this->echoGraph(3), []);

        $firstRow = DB::table('flow_run_nodes')->where('run_id', $first->runId)->where('node_id', 'c')->first();
        $secondRow = DB::table('flow_run_nodes')->where('run_id', $second->runId)->where('node_id', 'c')->first();

        $this->assertNull($firstRow->cache_hit, 'a fresh computation records no cache hit');
        $this->assertNotNull($secondRow->cache_hit, 'a served-from-cache node records its content hash');
        $this->assertSame(NodeState::Succeeded->value, $secondRow->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $secondRow->cache_hit);
    }

    public function test_different_inputs_do_not_share_a_cache_entry(): void
    {
        $this->runner()->run($this->echoGraph(1), []);
        $this->runner()->run($this->echoGraph(2), []);

        $this->assertSame(2, CacheableEchoNode::$invocations, 'distinct inputs miss independently');
        $this->assertSame(2, DB::table('flow_node_cache')->count());
    }

    public function test_dry_run_never_reads_or_writes_cache(): void
    {
        $this->app->make(FlowEngine::class)->dryRunGraph($this->echoGraph(5), []);

        $this->assertSame(0, DB::table('flow_node_cache')->count(), 'a dry run writes no cache rows');

        // A subsequent real run is still a miss (nothing was cached by the dry run).
        $this->runner()->run($this->echoGraph(5), []);
        $this->assertSame(1, DB::table('flow_node_cache')->count());
    }

    public function test_stored_outputs_pass_through_the_redaction_gate_like_every_other_persisted_payload(): void
    {
        $this->app['config']->set('laravel-flow.persistence.redaction.enabled', true);
        $this->app['config']->set('laravel-flow.persistence.redaction.keys', ['secret']);

        // The echo output has no redacted-list key, so it is cached unchanged —
        // the stored value equals the raw value (the write went through the gate
        // with nothing to redact), proving no bypass.
        $this->runner()->run($this->echoGraph(9), []);

        $row = DB::table('flow_node_cache')->first();
        $this->assertNotNull($row);
        $this->assertSame(['echoed' => 9], json_decode((string) $row->outputs, true));
    }

    public function test_output_containing_a_redacted_key_is_never_cached(): void
    {
        $this->app['config']->set('laravel-flow.persistence.redaction.enabled', true);
        $this->app['config']->set('laravel-flow.persistence.redaction.keys', ['secret']);

        $graph = new GraphDefinition([new GraphNode('s', 'test.cache.secret', ['value' => 4])], []);

        $this->runner()->run($graph, []);
        $this->assertSame(0, DB::table('flow_node_cache')->count(), 'a redacted output is never cached');

        // A second run is therefore still a miss: the handler runs again, so a
        // cache hit can never return a value that diverges from a fresh run.
        $this->runner()->run($graph, []);
        $this->assertSame(2, CacheableSecretNode::$invocations);
        $this->assertSame(0, DB::table('flow_node_cache')->count());
    }

    public function test_cache_infrastructure_failure_does_not_abort_the_node(): void
    {
        // Best-effort caching: a cache backend that throws on read AND write must
        // not fail the node — the handler runs and the run succeeds.
        $this->app->bind(NodeCacheRepository::class, fn (): NodeCacheRepository => new class implements NodeCacheRepository
        {
            public function find(string $contentHash, DateTimeInterface $now): ?NodeCacheHit
            {
                throw new RuntimeException('cache read down');
            }

            public function put(string $contentHash, string $nodeType, array $outputs, ?array $businessImpact, ?DateTimeInterface $expiresAt): void
            {
                throw new RuntimeException('cache write down');
            }
        });

        Log::spy();

        $result = $this->runner()->run($this->echoGraph(5), []);

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['c']);
        $this->assertSame(1, CacheableEchoNode::$invocations, 'handler ran despite the cache failure');
        $this->assertSame(['echoed' => 5], $result->nodeOutputs['c']);

        // The failure is logged (not silent) so a broken cache is observable.
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_cache_hit_clears_stale_error_fields_from_a_prior_failed_attempt(): void
    {
        $executor = $this->app->make(NodeExecutor::class);
        $store = $this->app->make(FlowStore::class);
        // Same node object for both invocations so the content hash is identical
        // (a hit on the second call is guaranteed).
        $node = new GraphNode('c', 'test.cache.echo', ['value' => 5]);

        // First invocation populates the cache (its own run row).
        DB::table('flow_runs')->insert([
            'id' => 'seed-run', 'definition_name' => 'graph', 'status' => 'running',
            'engine' => 'graph', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $executor->execute('seed-run', 'graph', $node, [], [], false, 0, $store);
        $this->assertSame(1, DB::table('flow_node_cache')->count(), 'cache populated');

        // A prior FAILED attempt on a DIFFERENT run left error/backoff fields on
        // its row (queued retry / replay reaches the same (run_id, node_id) row).
        DB::table('flow_runs')->insert([
            'id' => 'retry-run', 'definition_name' => 'graph', 'status' => 'running',
            'engine' => 'graph', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('flow_run_nodes')->insert([
            'run_id' => 'retry-run',
            'node_id' => 'c',
            'node_type' => 'test.cache.echo',
            'status' => 'failed',
            'attempts' => 1,
            'error_class' => 'RuntimeException',
            'error_message' => 'boom',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-invoke the same node for the failed run: it hits the cache and
        // persists success — the stale error fields must be cleared.
        $executor->execute('retry-run', 'graph', $node, [], [], false, 0, $store);

        $row = DB::table('flow_run_nodes')->where('run_id', 'retry-run')->where('node_id', 'c')->first();
        $this->assertSame('succeeded', $row->status);
        $this->assertNotNull($row->cache_hit, 'served from cache');
        $this->assertNull($row->error_class, 'stale error class cleared');
        $this->assertNull($row->error_message, 'stale error message cleared');
        $this->assertNull($row->available_at, 'stale backoff gate cleared');
    }

    public function test_paused_result_is_never_cached(): void
    {
        // A paused NodeResult carries `success === true` but its output is
        // partial (the node awaits external input). Caching it would let a later
        // run be served as a completed `succeeded`, silently skipping the pause.
        $graph = new GraphDefinition([new GraphNode('p', 'test.cache.pausing', ['value' => 5])], []);

        $this->runner()->run($graph, []);
        $this->assertSame(0, DB::table('flow_node_cache')->count(), 'a paused output is never cached');

        // A second run is therefore still a miss: the handler pauses again rather
        // than being resolved from a bogus cache entry.
        $this->runner()->run($graph, []);
        $this->assertSame(2, CacheablePausingNode::$invocations, 'no cache hit for a paused node');
        $this->assertSame(0, DB::table('flow_node_cache')->count());
    }

    public function test_ttl_expiry(): void
    {
        Date::setTestNow(Carbon::parse('2026-07-10 12:00:00'));
        $graph = new GraphDefinition([new GraphNode('t', 'test.cache.ttl', ['value' => 1])], []);

        $this->runner()->run($graph, []);
        $this->assertSame(1, CacheableTtlNode::$invocations);

        // Within the TTL window: still a hit.
        Date::setTestNow(Carbon::parse('2026-07-10 12:00:30'));
        $this->runner()->run($graph, []);
        $this->assertSame(1, CacheableTtlNode::$invocations, 'served from cache before expiry');

        // Past the 60s TTL: the entry has expired, so the handler runs again.
        Date::setTestNow(Carbon::parse('2026-07-10 12:02:00'));
        $this->runner()->run($graph, []);
        $this->assertSame(2, CacheableTtlNode::$invocations, 'expired entry misses and re-runs');
    }
}
