<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Routines;

use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Contracts\Target\ValidationResult;

/**
 * Lets a routine start a flow on a schedule.
 *
 * This class lives here, and not in laravel-routines, on purpose: the routine scheduler must not
 * know what a flow is. It knows *when*, *on whose behalf*, *under what ceiling* and *with what
 * outcome* — the target knows how to do the thing. Which is why laravel-flow depends on
 * `laravel-routines-contracts` (zero dependencies of its own) and never on the scheduler itself.
 *
 * Three seams matter here, and each one is the difference between an integration that works and
 * one that quietly does the wrong thing:
 *
 * 1. **Idempotency comes from the routine, not from here.** A flow started by a scheduled fire
 *    carries the fire's key, so a retry after a timeout resumes the same run instead of starting
 *    a second one. Generating a key here would generate a different one each attempt — exactly
 *    the bug the key exists to prevent.
 * 2. **A paused flow is a paused fire.** `FlowRun::STATUS_PAUSED` means the flow hit an approval
 *    gate and is waiting for a person. Reporting that as success would tell the ledger the work
 *    is done; reporting it as failure would retry a flow that is not broken. It maps to
 *    `TargetOutcome::Paused`, which is the case that exists for precisely this.
 * 3. **The delegated subject travels in the options, never in the input.** `flow_runs.input` is
 *    persisted unredacted — putting identity or a token there would write it to a table that
 *    outlives the run.
 */
final class FlowTarget implements RoutineTarget
{
    public function __construct(private readonly FlowEngine $engine) {}

    public function type(): string
    {
        return 'flow';
    }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor(
            label: 'Flow',
            summary: 'Runs a registered flow, with its approval gates and compensation.',
            fields: [
                'flow' => [
                    'label' => 'Flow',
                    'type' => 'select',
                    'required' => true,
                    'help' => 'One of the flows registered in this application.',
                    'options' => $this->flowOptions(),
                ],
                'input' => [
                    'label' => 'Input',
                    'type' => 'json',
                    'required' => false,
                    'help' => 'Merged with the input of each fire. Never put tokens or identity here.',
                ],
            ],
            // What the user authorises when granting the mandate. A flow can do a great deal, so
            // this stays deliberately coarse: the fine-grained ceiling belongs to the flow's own
            // approval gates, which is where the domain knowledge is.
            actionClasses: ['flow.execute'],
            supportsPause: true,
            reportsCost: false,
            icon: 'workflow',
        );
    }

    public function validate(array $payload): ValidationResult
    {
        $flow = $payload['flow'] ?? null;

        if (! is_string($flow) || $flow === '') {
            return ValidationResult::invalid(['flow' => ['Pick the flow to run.']]);
        }

        // Checked at creation, while a human is looking at the form. A misspelled flow name found
        // at 3am is a silent failure inside a log nobody reads.
        if (! isset($this->engine->definitions()[$flow])) {
            return ValidationResult::invalid([
                'flow' => [sprintf('No flow named "%s" is registered.', $flow)],
            ]);
        }

        if (isset($payload['input']) && ! is_array($payload['input'])) {
            return ValidationResult::invalid(['input' => ['The input must be an object.']]);
        }

        return ValidationResult::valid();
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        $flow = $execution->payload('flow');
        if (! is_string($flow) || $flow === '') {
            return TargetResult::failed('This routine has no flow configured.');
        }

        $configured = $execution->payload('input', []);
        $input = array_merge(
            is_array($configured) ? $configured : [],
            $execution->input,
            [
                // The fire's own coordinates, so a flow can be written to know it was scheduled
                // rather than triggered by a person — and which occurrence it is catching up on.
                'routine' => [
                    'id' => $execution->routine->id,
                    'name' => $execution->routine->name,
                    'reason' => $execution->reason->value,
                    'scheduled_for' => $execution->scheduledFor->format(\DateTimeInterface::ATOM),
                    'timezone' => $execution->timezone,
                    'attempt' => $execution->attempt,
                ],
            ],
        );

        try {
            $run = $this->engine->execute($flow, $input, new FlowExecutionOptions(
                correlationId: $execution->correlationId,
                // The routine's key, verbatim: a retry must resume, not duplicate.
                idempotencyKey: $execution->idempotencyKey,
                subject: $execution->routine->owner,
            ));
        } catch (FlowNotRegisteredException $e) {
            // The flow existed when the routine was created and does not now. Retrying will not
            // bring it back, and the message has to say that to whoever reads it.
            return TargetResult::failed(sprintf(
                'The flow "%s" is no longer registered, so this routine cannot run.',
                $flow,
            ));
        }

        return $this->interpret($run);
    }

    private function interpret(FlowRun $run): TargetResult
    {
        $metadata = [
            'flow' => $run->definitionName,
            'flow_run_id' => $run->id,
            'status' => $run->status,
            'compensated' => $run->compensated,
        ];

        return match ($run->status) {
            FlowRun::STATUS_SUCCEEDED => TargetResult::succeeded(
                sprintf('Flow "%s" completed.', $run->definitionName),
                $metadata,
                externalRef: $run->id,
            ),

            // Waiting on an approval gate. Not done, not broken — waiting for a person.
            FlowRun::STATUS_PAUSED => TargetResult::paused(
                sprintf(
                    'Flow "%s" is waiting for an approval before it can continue%s.',
                    $run->definitionName,
                    $run->failedStep === null ? '' : ' at step "'.$run->failedStep.'"',
                ),
                pendingApprovalId: $run->id,
                resumeToken: $run->id,
                metadata: $metadata + ['action_class' => 'flow.approve'],
            ),

            FlowRun::STATUS_FAILED, FlowRun::STATUS_COMPENSATED => TargetResult::failed(
                sprintf(
                    'Flow "%s" failed%s%s.',
                    $run->definitionName,
                    $run->failedStep === null ? '' : ' at step "'.$run->failedStep.'"',
                    $run->compensated ? ' and was compensated' : '',
                ),
                $metadata,
            ),

            FlowRun::STATUS_ABORTED => TargetResult::skipped(
                sprintf('Flow "%s" was aborted before doing any work.', $run->definitionName),
                $metadata,
            ),

            // Still running: the engine handed back before the flow finished (queued steps). Not
            // a failure — reporting it as one would retry work that is still in flight.
            default => TargetResult::succeeded(
                sprintf('Flow "%s" started and is still running.', $run->definitionName),
                $metadata,
                externalRef: $run->id,
            ),
        };
    }

    /** @return list<array{value: string, label: string}> */
    private function flowOptions(): array
    {
        $options = [];
        foreach (array_keys($this->engine->definitions()) as $name) {
            $options[] = ['value' => $name, 'label' => $name];
        }

        return $options;
    }
}
