<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\NodeChildRepository;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use RuntimeException;

/**
 * Shared child-run primitive for the fan-out / sub-flow control nodes: loads a
 * published child flow, and either runs it inline (synchronous executor) or
 * spawns it as a queued run — recording a {@see NodeChildRepository} ledger row
 * either way so a sub-flow is visible for audit/Dashboard in both paths.
 *
 * @internal
 */
final class ChildFlowRunner
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly DefinitionRepository $definitions,
        private readonly FlowEngine $engine,
        private readonly NodeChildRepository $children,
        private readonly Closure $clock,
    ) {}

    public function loadGraph(string $flow, ?int $version): GraphDefinition
    {
        $stored = $version !== null
            ? $this->definitions->find($flow, $version)
            : $this->definitions->latest($flow, 'published');

        if ($stored === null) {
            throw new RuntimeException("No published version of flow [{$flow}] is available to run as a child.");
        }

        return (new GraphSerializer)->fromArray($stored->graph);
    }

    /**
     * Run a child flow inline (synchronous path) and record it in the ledger as
     * a completed child. Returns the child's per-node output map and whether it
     * fully succeeded.
     *
     * @param  array<string, mixed>  $input
     * @return array{output: array<string, mixed>, succeeded: bool}
     */
    public function runInline(string $parentRunId, string $parentNodeId, GraphDefinition $child, array $input, int $childIndex): array
    {
        $result = $this->engine->runGraph($child, $input);
        $now = ($this->clock)();

        $this->children->record($parentRunId, $parentNodeId, $result->runId, $childIndex, $now);
        $this->children->completeChild($result->runId, $result->state->value, $result->nodeOutputs, $now);

        return [
            'output' => $result->nodeOutputs,
            'succeeded' => $result->state === RunState::Succeeded,
        ];
    }

    /**
     * Spawn a child flow as a queued run (queued path) and record it in the
     * ledger as a running child. Returns the child run id.
     *
     * @param  array<string, mixed>  $input
     */
    public function spawn(string $parentRunId, string $parentNodeId, GraphDefinition $child, array $input, int $childIndex): string
    {
        $childRunId = $this->engine->dispatchGraph($child, $input);
        $this->children->record($parentRunId, $parentNodeId, $childRunId, $childIndex, ($this->clock)());

        return $childRunId;
    }
}
