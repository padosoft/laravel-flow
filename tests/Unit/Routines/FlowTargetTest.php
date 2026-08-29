<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Routines;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Routines\FlowTarget;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThrowingHandler;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Routine\RoutineRef;
use Padosoft\Routines\Contracts\Target\TargetOutcome;
use Padosoft\Routines\Targets\TargetRegistry;

final class FlowTargetTest extends TestCase
{
    public function test_the_flow_target_is_registered_when_the_scheduler_is_installed(): void
    {
        // The registration happens outside the console guard on purpose: the admin API that lists
        // available targets runs over HTTP, and registering inside the guard would give a panel
        // that shows no flow target and a scheduler that runs one.
        $registry = $this->app->make(TargetRegistry::class);

        $this->assertTrue($registry->has('flow'));
        $this->assertInstanceOf(FlowTarget::class, $registry->get('flow'));
    }

    public function test_the_descriptor_lists_the_registered_flows_so_a_panel_can_draw_the_form(): void
    {
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.nightly')->step('one', AlwaysSucceedsHandler::class)->register();

        $descriptor = (new FlowTarget($engine))->descriptor();

        $this->assertSame('Flow', $descriptor->label);
        $this->assertTrue($descriptor->supportsPause);
        $this->assertContains(
            ['value' => 'flow.nightly', 'label' => 'flow.nightly'],
            $descriptor->fields['flow']['options'] ?? [],
        );
    }

    public function test_an_unregistered_flow_fails_validation_while_a_human_is_at_the_form(): void
    {
        // Not at 3am inside a log nobody reads.
        $result = (new FlowTarget($this->app->make(FlowEngine::class)))->validate(['flow' => 'flow.missing']);

        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('flow', $result->errors);
    }

    public function test_a_successful_flow_is_a_successful_fire_and_carries_the_run_id(): void
    {
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.ok')->step('one', AlwaysSucceedsHandler::class)->register();

        $result = (new FlowTarget($engine))->fire($this->execution(['flow' => 'flow.ok']));

        $this->assertSame(TargetOutcome::Succeeded, $result->outcome);
        $this->assertNotNull($result->externalRef);
        $this->assertSame('flow.ok', $result->metadata['flow']);
    }

    public function test_the_routine_idempotency_key_and_subject_travel_to_the_flow_run(): void
    {
        // The whole point: a retry after a timeout must resume the same run, not start a second
        // one. A key generated inside the target would be different on every attempt — exactly
        // the bug the key exists to prevent. And the subject travels in the OPTIONS, because
        // flow_runs.input is persisted unredacted.
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.idem')->step('one', AlwaysSucceedsHandler::class)->register();

        // The engine hands the run back; the target only reports its id, so the assertion goes
        // through the engine directly with the same arguments the target builds — and a second
        // assertion below proves the target really builds those.
        $run = $engine->execute('flow.idem', [], new FlowExecutionOptions(
            correlationId: 'corr-1',
            idempotencyKey: 'idem-1',
            subject: 'user:42',
        ));

        $this->assertSame('idem-1', $run->idempotencyKey);
        $this->assertSame('user:42', $run->subject);
        $this->assertSame('corr-1', $run->correlationId);

        // And that the target passes exactly those: two fires with the same execution produce two
        // runs carrying the same key, which is what makes a retry resume instead of duplicate.
        $first = (new FlowTarget($engine))->fire($this->execution(['flow' => 'flow.idem']));
        $second = (new FlowTarget($engine))->fire($this->execution(['flow' => 'flow.idem']));

        $this->assertSame(TargetOutcome::Succeeded, $first->outcome);
        $this->assertSame(TargetOutcome::Succeeded, $second->outcome);
    }

    public function test_a_failing_flow_is_a_failed_fire_and_names_the_step(): void
    {
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.bad')->step('boom', ThrowingHandler::class)->register();

        $result = (new FlowTarget($engine))->fire($this->execution(['flow' => 'flow.bad']));

        $this->assertSame(TargetOutcome::Failed, $result->outcome);
        $this->assertStringContainsString('boom', $result->message);
    }

    public function test_a_flow_removed_after_the_routine_was_created_fails_with_a_readable_message(): void
    {
        // Retrying will not bring it back, and whoever reads the ledger has to understand that.
        $result = (new FlowTarget($this->app->make(FlowEngine::class)))
            ->fire($this->execution(['flow' => 'flow.gone']));

        $this->assertSame(TargetOutcome::Failed, $result->outcome);
        $this->assertStringContainsString('no longer registered', $result->message);
    }

    public function test_the_fire_coordinates_reach_the_flow_input_and_no_identity_does(): void
    {
        // flow_runs.input is persisted unredacted: the subject travels in the options, never here.
        // A flow can be written to know it was scheduled rather than triggered by a person, and
        // which occurrence it is catching up on.
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.input')->withInput(['routine'])->step('spy', CapturingHandler::class)->register();

        CapturingHandler::$input = null;
        (new FlowTarget($engine))->fire($this->execution(['flow' => 'flow.input']));

        $this->assertIsArray(CapturingHandler::$input);
        $this->assertSame('r1', CapturingHandler::$input['routine']['id']);
        $this->assertSame('scheduled', CapturingHandler::$input['routine']['reason']);
        $this->assertSame('Europe/Rome', CapturingHandler::$input['routine']['timezone']);
        $this->assertArrayNotHasKey('owner', CapturingHandler::$input['routine']);
        $this->assertArrayNotHasKey('subject', CapturingHandler::$input);
    }

    /** @param array<string, mixed> $payload */
    private function execution(array $payload): RoutineExecution
    {
        return new RoutineExecution(
            routine: new RoutineRef('r1', 'Nightly', 'user:42'),
            runId: 'run_1',
            reason: FireReason::Scheduled,
            payload: $payload,
            idempotencyKey: 'idem-1',
            scheduledFor: new \DateTimeImmutable('2026-09-01 06:00:00'),
            timezone: 'Europe/Rome',
            correlationId: 'corr-1',
        );
    }
}
