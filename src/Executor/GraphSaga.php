<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Contracts\Container\Container;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\CompensatableNode;
use Padosoft\LaravelFlow\Node\NodeContext;
use Throwable;

/**
 * Graph-level saga compensation: when a graph run fails, ONLY the nodes that
 * completed ({@see NodeState::Succeeded}) roll back, in reverse-topological
 * order — a downstream node undoes its side effects before anything it
 * depended on. Three compensation sources compose:
 *
 *  - a handler implementing {@see CompensatableNode} (`compensate(NodeContext)`,
 *    the context's `inputs` carry the node's recorded outputs);
 *  - a compiled v1 step (`legacy.step`) whose `config['compensator']` names a
 *    {@see FlowCompensator}, invoked exactly like v1 (FlowContext + the step's
 *    FlowStepResult rebuilt from the node's `output` port);
 *  - the graph-level aggregate compensator (`metadata['aggregate_compensator']`,
 *    closing v1's reserved `withAggregateCompensator`), which ALWAYS runs last
 *    and receives every succeeded node's outputs.
 *
 * The `parallel` strategy batches per-node compensators through the Laravel
 * Concurrency driver (the caller opts in, asserting their independence — same
 * contract as v1's parallel step compensation); report building always stays
 * in the parent process. Every compensator failure is caught and recorded so
 * one bad compensator never aborts the remaining rollback (v1 invariant).
 *
 * @api
 */
final class GraphSaga
{
    public const STRATEGY_REVERSE_ORDER = 'reverse-order';

    public const STRATEGY_PARALLEL = 'parallel';

    /**
     * Key used in {@see GraphSagaReport::$errors} for an aggregate-compensator
     * failure — deliberately not a valid node id.
     */
    public const AGGREGATE_KEY = '@aggregate';

    public function __construct(
        private readonly NodeResolver $resolver,
        private readonly Container $container,
        private readonly ?ConcurrencyDriver $concurrencyDriver = null,
    ) {}

    /**
     * @param  array<string, NodeState>  $nodeStates
     * @param  array<string, array<string, mixed>>  $nodeOutputs  succeeded node id => output port map
     * @param  array<string, mixed>  $runInput  the original run input (forwarded to v1 compensators' FlowContext)
     */
    public function compensate(
        string $runId,
        string $definitionName,
        GraphDefinition $graph,
        array $nodeStates,
        array $nodeOutputs,
        string $strategy = self::STRATEGY_REVERSE_ORDER,
        array $runInput = [],
    ): GraphSagaReport {
        $this->assertSupportedStrategy($strategy);

        $candidates = $this->candidates($graph, $nodeStates);

        [$compensated, $errors] = $strategy === self::STRATEGY_PARALLEL
            ? $this->runParallel($candidates, $runId, $definitionName, $nodeOutputs, $runInput)
            : $this->runSequential($candidates, $runId, $definitionName, $nodeOutputs, $runInput);

        // The aggregate compensator is the FINAL graph-level rollback: it runs
        // last (after every per-node compensator, in both strategies), in the
        // parent process, whenever the saga runs and one is declared.
        $aggregateCompensated = false;

        try {
            $aggregateCompensated = $this->runAggregate($graph, $runId, $definitionName, $nodeOutputs, $runInput);
        } catch (Throwable $e) {
            $errors[self::AGGREGATE_KEY] = $e->getMessage();
        }

        return new GraphSagaReport($compensated, $errors, $aggregateCompensated);
    }

    /**
     * Collect the compensation candidates in reverse-topological order: ONLY
     * nodes that actually completed — a failed, blocked, skipped, paused, or
     * never-run node has no committed side effects to undo.
     *
     * @param  array<string, NodeState>  $nodeStates
     * @return list<array{node: GraphNode, legacyCompensator: string|null}>
     */
    private function candidates(GraphDefinition $graph, array $nodeStates): array
    {
        $candidates = [];

        foreach (array_reverse($graph->topologicalOrder()) as $id) {
            if (($nodeStates[$id] ?? NodeState::Pending) !== NodeState::Succeeded) {
                continue;
            }

            $node = $graph->node($id);

            if ($node === null) {
                continue;
            }

            $legacyCompensator = $node->config['compensator'] ?? null;
            $legacyCompensator = is_string($legacyCompensator) && $legacyCompensator !== '' ? $legacyCompensator : null;

            if ($legacyCompensator !== null || $this->handlerIsCompensatable($node)) {
                $candidates[] = ['node' => $node, 'legacyCompensator' => $legacyCompensator];
            }
        }

        return $candidates;
    }

    private function handlerIsCompensatable(GraphNode $node): bool
    {
        // The node already resolved once to RUN, so a resolution failure here is
        // an infrastructure change mid-run; treat it as "not compensatable"
        // rather than aborting the remaining rollback.
        try {
            return $this->resolver->resolve($node)->handler instanceof CompensatableNode;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array{node: GraphNode, legacyCompensator: string|null}>  $candidates
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, mixed>  $runInput
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function runSequential(array $candidates, string $runId, string $definitionName, array $nodeOutputs, array $runInput): array
    {
        $compensated = [];
        $errors = [];

        foreach ($candidates as $candidate) {
            $id = $candidate['node']->id;

            try {
                $this->compensateOne($candidate, $runId, $definitionName, $nodeOutputs, $runInput);
                $compensated[] = $id;
            } catch (Throwable $e) {
                // Record and KEEP GOING: the whole point of saga compensation is
                // best-effort rollback of every completed node — an early abort
                // would leave upstream side effects unapplied exactly when
                // partial-failure rollback matters most (v1 invariant).
                $errors[$id] = $e->getMessage();
            }
        }

        return [$compensated, $errors];
    }

    /**
     * Batch every candidate through the concurrency driver (the caller opted in
     * to `parallel`, asserting compensator independence — v1's contract). The
     * driver path needs the GLOBAL container (tasks may run in another process
     * and re-resolve there); otherwise fall back to sequential local execution
     * so rollback still happens. Report building stays in the parent process.
     *
     * @param  list<array{node: GraphNode, legacyCompensator: string|null}>  $candidates
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, mixed>  $runInput
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function runParallel(array $candidates, string $runId, string $definitionName, array $nodeOutputs, array $runInput): array
    {
        if ($candidates === []) {
            return [[], []];
        }

        if (! $this->concurrencyDriver instanceof ConcurrencyDriver || ! $this->containerIsGlobalInstance()) {
            return $this->runSequential($candidates, $runId, $definitionName, $nodeOutputs, $runInput);
        }

        $tasks = [];

        foreach ($candidates as $index => $candidate) {
            $tasks[$index] = $this->parallelTask($candidate, $runId, $definitionName, $nodeOutputs, $runInput);
        }

        try {
            /** @var array<int, array{success: bool, error_message?: string}> $results */
            $results = $this->concurrencyDriver->run($tasks);
        } catch (Throwable) {
            // The driver could not run the batch (e.g. no process runtime):
            // fall back to local rollback rather than leaving side effects
            // unapplied (v1 invariant).
            return $this->runSequential($candidates, $runId, $definitionName, $nodeOutputs, $runInput);
        }

        $compensated = [];
        $errors = [];

        foreach ($candidates as $index => $candidate) {
            $id = $candidate['node']->id;
            $result = $results[$index] ?? null;

            if (is_array($result) && ($result['success'] ?? false) === true) {
                $compensated[] = $id;

                continue;
            }

            $errors[$id] = is_array($result)
                ? (string) ($result['error_message'] ?? 'Parallel compensation task failed.')
                : 'Parallel compensation task did not return a result.';
        }

        return [$compensated, $errors];
    }

    /**
     * Self-contained task for the concurrency driver: it re-resolves everything
     * from the global container so it can run in another process.
     *
     * @param  array{node: GraphNode, legacyCompensator: string|null}  $candidate
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, mixed>  $runInput
     * @return Closure(): array{success: bool, error_message?: string}
     */
    private function parallelTask(array $candidate, string $runId, string $definitionName, array $nodeOutputs, array $runInput): Closure
    {
        return static function () use ($candidate, $runId, $definitionName, $nodeOutputs, $runInput): array {
            $container = \Illuminate\Container\Container::getInstance();

            try {
                $saga = new self(
                    $container->make(NodeResolver::class),
                    $container,
                );
                $saga->compensateOne($candidate, $runId, $definitionName, $nodeOutputs, $runInput);
            } catch (Throwable $e) {
                return ['success' => false, 'error_message' => $e->getMessage()];
            }

            return ['success' => true];
        };
    }

    /**
     * Run ONE candidate's compensator; throws on any failure (callers record).
     *
     * @param  array{node: GraphNode, legacyCompensator: string|null}  $candidate
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, mixed>  $runInput
     */
    private function compensateOne(array $candidate, string $runId, string $definitionName, array $nodeOutputs, array $runInput): void
    {
        $node = $candidate['node'];
        $outputs = $nodeOutputs[$node->id] ?? [];

        if ($candidate['legacyCompensator'] !== null) {
            // v1 semantics preserved 1:1: the compensator receives the original
            // run input via FlowContext and the step's result rebuilt from the
            // adapted node's single `output` port.
            $stepOutput = $outputs['output'] ?? [];
            $this->makeV1Compensator($candidate['legacyCompensator'], $node->id)->compensate(
                new FlowContext(
                    flowRunId: $runId,
                    definitionName: $definitionName,
                    input: $runInput,
                    stepOutputs: [],
                    dryRun: false,
                ),
                FlowStepResult::success(is_array($stepOutput) ? $stepOutput : []),
            );

            return;
        }

        $handler = $this->resolver->resolve($node)->handler;

        if (! $handler instanceof CompensatableNode) {
            throw new FlowCompensationException(sprintf(
                'Handler for node [%s] no longer implements %s.',
                $node->id,
                CompensatableNode::class,
            ));
        }

        // The context's `inputs` deliberately carry the node's recorded OUTPUTS:
        // compensation undoes what the node produced (see CompensatableNode).
        $handler->compensate(new NodeContext($runId, $definitionName, $node->id, $outputs));
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, mixed>  $runInput
     */
    private function runAggregate(GraphDefinition $graph, string $runId, string $definitionName, array $nodeOutputs, array $runInput): bool
    {
        $aggregate = $graph->metadata['aggregate_compensator'] ?? null;

        if (! is_string($aggregate) || $aggregate === '') {
            return false;
        }

        // The aggregate compensator sees EVERY succeeded node's outputs (keyed
        // by node id) as the "result" it must roll back at graph level.
        $this->makeV1Compensator($aggregate, self::AGGREGATE_KEY)->compensate(
            new FlowContext(
                flowRunId: $runId,
                definitionName: $definitionName,
                input: $runInput,
                stepOutputs: [],
                dryRun: false,
            ),
            FlowStepResult::success($nodeOutputs),
        );

        return true;
    }

    private function makeV1Compensator(string $fqcn, string $forId): FlowCompensator
    {
        $compensator = $this->container->make($fqcn);

        if (! $compensator instanceof FlowCompensator) {
            throw new FlowCompensationException(sprintf(
                'Compensator [%s] for [%s] does not implement %s.',
                $fqcn,
                $forId,
                FlowCompensator::class,
            ));
        }

        return $compensator;
    }

    private function containerIsGlobalInstance(): bool
    {
        return \Illuminate\Container\Container::getInstance() === $this->container;
    }

    private function assertSupportedStrategy(string $strategy): void
    {
        if (! in_array($strategy, [self::STRATEGY_REVERSE_ORDER, self::STRATEGY_PARALLEL], true)) {
            throw new FlowInputException(sprintf(
                'Unsupported compensation strategy [%s]. Supported strategies: %s, %s.',
                $strategy,
                self::STRATEGY_REVERSE_ORDER,
                self::STRATEGY_PARALLEL,
            ));
        }
    }
}
