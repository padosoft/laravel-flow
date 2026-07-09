<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use JsonException;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\FlowStep;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
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
            $steps = $store->steps()->forRun($original->id);
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
        ], true);
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
     * @param  list<FlowStepRecord>  $persistedSteps
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
        } catch (JsonException $e) {
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
     * @param  list<FlowStepRecord>  $persistedSteps
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

            if ($currentStep->name !== $persistedStep->step_name || $currentStep->handlerFqcn !== $persistedStep->handler) {
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
