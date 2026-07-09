<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphNode;
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
    ): NodeExecution {
        $startedAt = ($this->clock)();

        try {
            $resolved = $this->resolver->resolve($node);
        } catch (Throwable $e) {
            // A resolution failure (unknown/unbound handler, bad legacy config)
            // must not leave the run stuck `running` with no node row — record
            // it as a failed node, mirroring v1's handler-resolution behaviour.
            $this->persist($store, $runId, $node, $sequence, [
                'status' => NodeState::Failed->value,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'dry_run_skipped' => false,
                'started_at' => $startedAt,
                'finished_at' => ($this->clock)(),
                'duration_ms' => 0,
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

        $context = new NodeContext($runId, $definitionName, $node->id, $routed->inputs, $dryRun);

        try {
            $result = $resolved->handler->execute($context);
        } catch (Throwable $e) {
            $result = NodeResult::failed($e);
        }

        $finishedAt = ($this->clock)();
        $state = $this->stateFor($result);

        $this->persist($store, $runId, $node, $sequence, [
            'handler' => $resolved->definition->handlerClass,
            'inputs' => $routed->inputs,
            'outputs' => $result->success ? $result->outputs : null,
            'business_impact' => $result->businessImpact,
            'error_class' => $result->error instanceof Throwable ? $result->error::class : null,
            'error_message' => $result->error?->getMessage(),
            'dry_run_skipped' => $result->dryRunSkipped,
            'status' => $state->value,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($startedAt, $finishedAt),
        ]);

        return new NodeExecution($node->id, $state, $result->success ? $result->outputs : [], $result->error);
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
