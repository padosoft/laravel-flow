<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use JsonException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\FlowStep;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Throwable;

/**
 * @internal
 */
final class ReplayFlowRunCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:replay
        {runId : Persisted flow run id to replay}';

    /**
     * @var string
     */
    protected $description = 'Replay a terminal persisted Laravel Flow run as a new linked run.';

    public function handle(): int
    {
        if (! (bool) $this->getLaravel()->make('config')->get('laravel-flow.persistence.enabled', false)) {
            $this->error('Enable laravel-flow.persistence.enabled before replaying persisted runs.');

            return self::FAILURE;
        }

        $runId = (string) $this->argument('runId');

        try {
            /** @var FlowStore $store */
            $store = $this->getLaravel()->make(FlowStore::class);
            $original = $store->runs()->find($runId);
        } catch (QueryException $e) {
            $this->reportFailure(
                'Laravel Flow persistence tables were not found or could not be queried. Publish and run the migrations before replaying.',
                $e,
            );

            return self::FAILURE;
        }

        if (! ($original instanceof FlowRunRecord)) {
            $this->error(sprintf('Flow run [%s] was not found.', $runId));

            return self::FAILURE;
        }

        if (! $this->isTerminal($original)) {
            $this->error(sprintf('Flow run [%s] is not terminal and cannot be replayed.', $runId));

            return self::FAILURE;
        }

        $input = $original->input ?? [];

        if (! is_array($input)) {
            $this->error(sprintf('Flow run [%s] does not have replayable array input.', $runId));

            return self::FAILURE;
        }

        /** @var FlowEngine $flow */
        $flow = $this->getLaravel()->make(FlowEngine::class);

        if ($this->isPinnedGraphRun($original)) {
            return $this->replayPinnedGraph($flow, $original, $input);
        }

        try {
            $definition = $flow->definition($original->definition_name);
        } catch (FlowNotRegisteredException $e) {
            $this->reportFailure(
                sprintf('Flow definition [%s] is not registered in the current application.', $original->definition_name),
                $e,
            );

            return self::FAILURE;
        }

        try {
            $steps = $store->runNodes()->forRun($original->id);
        } catch (QueryException $e) {
            $this->reportFailure(
                'Laravel Flow persistence tables were not found or could not be queried. Publish and run the migrations before replaying.',
                $e,
            );

            return self::FAILURE;
        }

        $this->warnAboutDrift($definition, $steps->all(), $original);

        try {
            $run = $flow->execute(
                $definition->name,
                $input,
                FlowExecutionOptions::make(
                    correlationId: $original->correlation_id,
                    replayedFromRunId: $original->id,
                ),
            );
        } catch (QueryException $e) {
            $this->reportFailure(
                'Laravel Flow replay could not persist or query its tables. Publish and run the latest migrations before replaying.',
                $e,
            );

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->reportFailure('Laravel Flow replay failed before a linked run could be completed.', $e);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Replayed flow run [%s] as [%s] with status [%s].',
            $original->id,
            $run->id,
            $run->status,
        ));

        return self::SUCCESS;
    }

    private function isTerminal(FlowRunRecord $run): bool
    {
        return in_array($run->status, [
            FlowRun::STATUS_ABORTED,
            FlowRun::STATUS_COMPENSATED,
            FlowRun::STATUS_FAILED,
            FlowRun::STATUS_SUCCEEDED,
            RunState::PartiallySucceeded->value, // graph-executor terminal run states
            RunState::DeadLetter->value,
        ], true);
    }

    private function isPinnedGraphRun(FlowRunRecord $run): bool
    {
        return $run->engine === 'graph'
            && $run->definition_version !== null
            && $run->definition_checksum !== null;
    }

    /**
     * Version-exact replay: a pinned graph run re-executes the EXACT stored
     * graph version through the graph executor, regardless of what the current
     * `latest()` version is (a checksum mismatch is informational).
     *
     * @param  array<string, mixed>  $input
     */
    private function replayPinnedGraph(FlowEngine $flow, FlowRunRecord $original, array $input): int
    {
        $name = (string) $original->definition_name;
        $version = (int) $original->definition_version;

        try {
            /** @var DefinitionRepository $definitions */
            $definitions = $this->getLaravel()->make(DefinitionRepository::class);
            $stored = $definitions->find($name, $version);
        } catch (QueryException $e) {
            $this->reportFailure(
                'Laravel Flow persistence tables were not found or could not be queried. Publish and run the migrations before replaying.',
                $e,
            );

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->reportFailure(
                sprintf('Stored graph version [%d] for definition [%s] could not be loaded for replay.', $version, $name),
                $e,
            );

            return self::FAILURE;
        }

        try {
            $graph = (new GraphSerializer)->fromArray($stored->graph);
        } catch (InvalidGraphException|JsonException $e) {
            $this->reportFailure(
                sprintf('Stored graph version [%d] for definition [%s] could not be rebuilt for replay.', $version, $name),
                $e,
            );

            return self::FAILURE;
        }

        try {
            $result = $flow->runGraph(
                $graph,
                $input,
                FlowExecutionOptions::make(
                    correlationId: $original->correlation_id,
                    replayedFromRunId: $original->id,
                ),
                $name,
            );
        } catch (QueryException $e) {
            $this->reportFailure(
                'Laravel Flow replay could not persist or query its tables. Publish and run the latest migrations before replaying.',
                $e,
            );

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->reportFailure('Laravel Flow graph replay failed before a linked run could be completed.', $e);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Replayed graph run [%s] as [%s] using pinned version [%d] with status [%s].',
            $original->id,
            $result->runId,
            $version,
            $result->state->value,
        ));

        return self::SUCCESS;
    }

    /**
     * Pinned runs (a non-null `definition_checksum` recorded at run
     * creation) get a checksum-aware drift check instead of the step-list
     * comparison: the recorded content checksum is authoritative, so a
     * byte-identical current graph never warns even if step-level
     * metadata was reshuffled in a way {@see self::definitionDrifted()}
     * could not see. Unpinned (legacy) runs keep the original step-name /
     * handler prefix check.
     *
     * @param  list<FlowRunNodeRecord>  $persistedSteps
     */
    private function warnAboutDrift(FlowDefinition $definition, array $persistedSteps, FlowRunRecord $original): void
    {
        if ($original->definition_checksum !== null) {
            $this->warnAboutPinnedDrift($definition, $original);

            return;
        }

        if ($this->definitionDrifted($definition, $persistedSteps)) {
            $this->warn(sprintf(
                'Definition drift detected for [%s]; replay will use the currently registered definition.',
                $definition->name,
            ));
        }
    }

    private function warnAboutPinnedDrift(FlowDefinition $definition, FlowRunRecord $original): void
    {
        try {
            $currentChecksum = (new GraphSerializer)->checksum($definition->toGraphDefinition());
        } catch (JsonException|InvalidGraphException $e) {
            $this->warn(sprintf(
                'Could not evaluate definition drift for flow run [%s]; replay continues without a drift check.',
                $original->id,
            ));

            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getMessage());
            }

            return;
        }

        if ($currentChecksum === $original->definition_checksum) {
            return;
        }

        $this->warn(sprintf(
            'Flow run [%s] was pinned to [%s] version [%s]; the registered definition has changed since then. Replay will use the currently registered definition (graph-exact re-execution ships with Macro C).',
            $original->id,
            $definition->name,
            $original->definition_version === null ? 'unknown' : (string) $original->definition_version,
        ));
    }

    /**
     * @param  list<FlowRunNodeRecord>  $persistedSteps
     */
    private function definitionDrifted(FlowDefinition $definition, array $persistedSteps): bool
    {
        if ($persistedSteps === []) {
            return false;
        }

        foreach ($persistedSteps as $index => $persistedStep) {
            $currentStep = $definition->steps[$index] ?? null;

            if (! ($currentStep instanceof FlowStep)) {
                return true;
            }

            if ($currentStep->name !== $persistedStep->node_id || $currentStep->handlerFqcn !== $persistedStep->handler) {
                return true;
            }
        }

        return false;
    }

    private function reportFailure(string $message, Throwable $exception): void
    {
        $this->error($message);

        if ($this->getOutput()->isVerbose()) {
            $this->line($exception->getMessage());
        }
    }
}
