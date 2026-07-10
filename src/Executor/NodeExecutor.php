<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Throwable;

/**
 * The single place a graph node is routed → validated → executed → persisted.
 * Both the synchronous {@see GraphRunner} and the future queued coordinator go
 * through here, so the two paths can never diverge. A routing/validation
 * failure short-circuits to `invalid_input` WITHOUT calling the handler; a
 * throwing handler is caught and mapped to `failed`. Persistence writes are
 * skipped entirely when `$store` is null (dry-run / persistence disabled), so
 * a dry run leaves zero rows.
 *
 * @api
 */
final class NodeExecutor
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly NodeResolver $resolver,
        private readonly InputRouter $router,
        private readonly Closure $clock,
        private readonly ?NodeCache $cache = null,
    ) {}

    /**
     * @param  list<Connection>  $connectionsIntoNode
     * @param  array<string, array<string, mixed>>  $upstreamOutputs
     */
    public function execute(
        string $runId,
        string $definitionName,
        GraphNode $node,
        array $connectionsIntoNode,
        array $upstreamOutputs,
        bool $dryRun,
        int $sequence,
        ?FlowStore $store,
        bool $queued = false,
    ): NodeExecution {
        $startedAt = ($this->clock)();

        try {
            $resolved = $this->resolver->resolve($node);
        } catch (Throwable $e) {
            // A resolution failure (unknown/unbound handler, bad legacy config)
            // must not leave the run stuck `running` with no node row — record
            // it as a failed node, mirroring v1's handler-resolution behaviour.
            $finishedAt = ($this->clock)();
            $this->persist($store, $runId, $node, $sequence, [
                'status' => NodeState::Failed->value,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'dry_run_skipped' => false,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $this->durationMs($startedAt, $finishedAt),
            ]);

            return new NodeExecution($node->id, NodeState::Failed, [], $e);
        }

        $routed = $this->router->route($resolved->definition, $node, $connectionsIntoNode, $upstreamOutputs);

        if (! $routed->valid) {
            $this->persist($store, $runId, $node, $sequence, [
                'handler' => $resolved->definition->handlerClass,
                'status' => NodeState::InvalidInput->value,
                'error_class' => $routed->violation !== null ? $routed->violation::class : null,
                'error_message' => $routed->violation?->getMessage(),
                'dry_run_skipped' => false,
                'started_at' => $startedAt,
                'finished_at' => $startedAt,
                'duration_ms' => 0,
            ]);

            return new NodeExecution($node->id, NodeState::InvalidInput, [], $routed->violation);
        }

        $definition = $resolved->definition;
        $contentHash = null;

        // Content-hash cache: only for a #[Cacheable] node on a real persisted
        // run. $store is null on a dry run / when persistence is off, so the
        // cache is inert there (never read, never written).
        if ($this->cache !== null && $store !== null && ! $dryRun && $definition->cacheable !== null) {
            // Caching is an optional optimization: a cache-infrastructure failure
            // (missing table mid-upgrade, DB or JSON error) must never abort node
            // execution. On any failure, skip the cache for THIS node execution
            // ($contentHash stays null) and fall through to a normal handler run.
            $hit = null;

            try {
                $contentHash = $this->cache->hash($node->type, $routed->inputs, $node->config);
                $hit = $this->cache->get($contentHash);
            } catch (Throwable $e) {
                $contentHash = null;
                // Fail-safe, but not silent: an operator needs a signal that
                // caching stopped working (e.g. a missing table mid-upgrade),
                // otherwise it degrades invisibly. Log only the exception CLASS
                // and code — a QueryException message embeds the SQL + bound
                // params (the node payload), which must never reach the logs.
                Log::warning('laravel-flow: node cache read failed; running the node without cache.', [
                    'node_type' => $node->type,
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }

            if ($hit !== null) {
                $finishedAt = ($this->clock)();
                $this->persist($store, $runId, $node, $sequence, [
                    'handler' => $definition->handlerClass,
                    'attempts' => 0,
                    'inputs' => $routed->inputs,
                    'outputs' => $hit->outputs,
                    'business_impact' => $hit->businessImpact,
                    // Clear any error/backoff fields a PRIOR failed attempt on
                    // this same (run_id, node_id) row left behind — the upsert
                    // only writes provided keys, so a cache hit on a retry would
                    // otherwise persist a `succeeded` row with stale error data.
                    'error_class' => null,
                    'error_message' => null,
                    'available_at' => null,
                    'dry_run_skipped' => false,
                    'status' => NodeState::Succeeded->value,
                    'cache_hit' => $contentHash,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $this->durationMs($startedAt, $finishedAt),
                ]);

                return new NodeExecution($node->id, NodeState::Succeeded, $hit->outputs, null);
            }
        }

        $context = new NodeContext($runId, $definitionName, $node->id, $routed->inputs, $dryRun, $queued);
        $policy = ($resolved->definition->retry ?? RetryPolicy::fromAttribute(null))->withConfig($this->configRetry($node));

        $attempts = 0;
        $availableAt = null;

        while (true) {
            $attemptStartedAt = ($this->clock)();
            $attempts++;
            $result = $this->runAttempt($resolved->handler, $context, $policy, $attemptStartedAt);

            // A success/paused/skipped attempt, or an exhausted retry budget,
            // is terminal. `Failed -> Running` retry re-entry stays inline.
            if ($result->success || $result->paused || $result->dryRunSkipped) {
                $state = $this->stateFor($result);

                break;
            }

            if ($policy->isExhausted($attempts)) {
                // Dead-letter only when a real retry budget (tries > 1) was
                // exhausted; a single-attempt node that fails is just Failed.
                $state = $policy->tries() > 1 ? NodeState::DeadLetter : $this->stateFor($result);

                break;
            }

            // The delay before the Nth retry uses the Nth backoff entry: after
            // $attempts failures the upcoming retry is number $attempts, so the
            // first retry (list index 0) is backoffForAttempt($attempts).
            $backoff = $policy->backoffForAttempt($attempts);
            $availableAt = ($this->clock)()->modify("+{$backoff} seconds");

            // A dry run must not delay the process (no executor-driven side
            // effects); it still records the computed schedule.
            if (! $dryRun) {
                Sleep::for($backoff)->seconds();
            }
        }

        $finishedAt = ($this->clock)();

        $this->persist($store, $runId, $node, $sequence, [
            'handler' => $resolved->definition->handlerClass,
            'attempts' => $attempts,
            'inputs' => $routed->inputs,
            'outputs' => $result->success ? $result->outputs : null,
            'business_impact' => $result->businessImpact,
            'error_class' => $result->error instanceof Throwable ? $result->error::class : null,
            'error_message' => $result->error?->getMessage(),
            'dry_run_skipped' => $result->dryRunSkipped,
            'status' => $state->value,
            'available_at' => $availableAt,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($startedAt, $finishedAt),
        ]);

        // Populate the cache after a fresh success (redaction gate + skip-on-
        // divergence enforced inside NodeCache::put()). Best-effort: a failed
        // cache write must not fail a node whose handler already succeeded.
        if ($contentHash !== null && $this->cache !== null && $definition->cacheable !== null && $result->success) {
            try {
                $this->cache->put($contentHash, $node->type, $result->outputs, $result->businessImpact, $definition->cacheable->ttl);
            } catch (Throwable $e) {
                // Optional optimization: a write failure never fails a node whose
                // handler already succeeded, but log it (exception CLASS + code
                // only — never the message, which for a QueryException embeds the
                // SQL + bound params, i.e. the node payload) so a broken cache
                // does not degrade invisibly.
                Log::warning('laravel-flow: node cache write failed; the node succeeded without caching its output.', [
                    'node_type' => $node->type,
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        return new NodeExecution($node->id, $state, $result->success ? $result->outputs : [], $result->error);
    }

    /**
     * Run one attempt. A throwing handler becomes a failed result; a
     * successful attempt that overran `timeout` seconds is treated as failed
     * (post-hoc wall-clock check — the synchronous runner cannot preempt; true
     * preemptive timeout is a queue-worker concern).
     */
    private function runAttempt(FlowNodeHandler $handler, NodeContext $context, RetryPolicy $policy, DateTimeImmutable $attemptStartedAt): NodeResult
    {
        try {
            $result = $handler->execute($context);
        } catch (Throwable $e) {
            return NodeResult::failed($e);
        }

        if ($policy->timeout() > 0 && $result->success) {
            $elapsed = (float) ($this->clock)()->format('U.u') - (float) $attemptStartedAt->format('U.u');

            if ($elapsed > $policy->timeout()) {
                return NodeResult::failed(new NodeTimeoutException(sprintf('Node exceeded its %d second timeout.', $policy->timeout())));
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function configRetry(GraphNode $node): array
    {
        $retry = $node->config['retry'] ?? [];

        return is_array($retry) ? $retry : [];
    }

    private function stateFor(NodeResult $result): NodeState
    {
        if ($result->paused) {
            return NodeState::Paused;
        }

        if (! $result->success) {
            return NodeState::Failed;
        }

        return $result->dryRunSkipped ? NodeState::Skipped : NodeState::Succeeded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(?FlowStore $store, string $runId, GraphNode $node, int $sequence, array $attributes): void
    {
        if ($store === null) {
            return; // dry-run / persistence disabled: zero rows
        }

        $store->runNodes()->createOrUpdate($runId, $node->id, [
            'node_type' => $node->type,
            'sequence' => $sequence,
            ...$attributes,
        ]);
    }

    private function durationMs(DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt): int
    {
        return (int) round(((float) $finishedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000);
    }
}
