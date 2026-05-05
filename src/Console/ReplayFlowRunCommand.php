<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\FlowStep;
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

        if ($this->definitionDrifted($definition, $steps->all())) {
            $this->warn(sprintf(
                'Definition drift detected for [%s]; replay will use the currently registered definition.',
                $definition->name,
            ));
        }

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
