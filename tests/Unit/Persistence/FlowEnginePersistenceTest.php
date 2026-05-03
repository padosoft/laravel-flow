<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ClockAdvancingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\CustomSecretFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecretFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThrowingCompensator;
use RuntimeException;

final class FlowEnginePersistenceTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingCompensator::reset();
    }

    public function test_successful_flow_is_persisted_when_persistence_is_enabled(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();
        $frozen = Carbon::parse('2026-05-02 09:00:00');

        $engine->define('flow.persist.success')
            ->withInput(['token'])
            ->step('create', AlwaysSucceedsHandler::class)
            ->step('impact', DryRunAwareHandler::class)
            ->withDryRun(true)
            ->register();

        Date::setTestNow($frozen);

        try {
            $run = $engine->execute('flow.persist.success', [
                'safe' => 'visible',
                'token' => 'plain-secret',
            ]);
        } finally {
            Date::setTestNow();
        }

        $runRecord = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $runRecord->status);
        $this->assertSame($frozen->getTimestamp(), $runRecord->started_at->getTimestamp());
        $this->assertSame($frozen->getTimestamp(), $runRecord->finished_at->getTimestamp());
        $this->assertSame('[redacted]', $runRecord->input['token']);
        $this->assertSame('visible', $runRecord->input['safe']);
        $this->assertSame(['projected_writes' => 5], $runRecord->business_impact['impact']);
        $this->assertArrayHasKey('create', $runRecord->output);
        $this->assertNotNull($runRecord->finished_at);
        $this->assertIsInt($runRecord->duration_ms);

        $steps = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $steps);
        $this->assertSame(['create', 'impact'], $steps->pluck('step_name')->all());
        $this->assertSame(['succeeded', 'succeeded'], $steps->pluck('status')->all());
        $this->assertSame($frozen->getTimestamp(), $steps[0]->started_at->getTimestamp());
        $this->assertSame('[redacted]', $steps[0]->input['flow_input']['token']);
        $this->assertSame(['projected_writes' => 5], $steps[1]->business_impact);

        $auditEvents = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->orderBy('id')
            ->pluck('event')
            ->all();

        $this->assertSame([
            'FlowStepStarted',
            'FlowStepCompleted',
            'FlowStepStarted',
            'FlowStepCompleted',
        ], $auditEvents);
    }

    public function test_failed_flow_persists_failed_step_and_compensation_state(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.failure')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.failure', []);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertSame('charge', $runRecord->failed_step);
        $this->assertTrue($runRecord->compensated);
        $this->assertSame('succeeded', $runRecord->compensation_status);

        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertSame('failed', $failedStep->status);
        $this->assertSame(RuntimeException::class, $failedStep->error_class);
        $this->assertSame('boom', $failedStep->error_message);

        $this->assertSame([
            'FlowStepStarted',
            'FlowStepCompleted',
            'FlowStepStarted',
            'FlowStepFailed',
            'FlowCompensated',
        ], FlowAuditRecord::query()->where('run_id', $run->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_compensated_run_finished_at_includes_compensation_time(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();
        $started = Carbon::parse('2026-05-02 10:00:00');
        $compensated = Carbon::parse('2026-05-02 10:00:05');

        $engine->define('flow.persist.compensation-time')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(ClockAdvancingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        Date::setTestNow($started);

        try {
            $run = $engine->execute('flow.persist.compensation-time', []);
        } finally {
            Date::setTestNow();
        }

        $runRecord = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertSame($compensated->getTimestamp(), $runRecord->finished_at->getTimestamp());
        $this->assertSame(5000, $runRecord->duration_ms);

        $compensatedAudit = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowCompensated')
            ->first();

        $this->assertInstanceOf(FlowAuditRecord::class, $compensatedAudit);
        $this->assertSame($compensated->getTimestamp(), $compensatedAudit->occurred_at->getTimestamp());
    }

    public function test_persisted_error_messages_are_sanitized_before_storage(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.secret-error')
            ->step('charge', SecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.secret-error', []);

        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();
        $failedAudit = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowStepFailed')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertStringNotContainsString('plain-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('camel-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('dash-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('auth-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('abc123', (string) $failedStep->error_message);
        $this->assertStringContainsString('[redacted]', (string) $failedStep->error_message);
        $this->assertSame($failedStep->error_message, $failedAudit->payload['error_message']);
    }

    public function test_persisted_error_messages_use_custom_payload_redactor_binding(): void
    {
        $this->migrateFlowTables();
        $this->app->singleton(PayloadRedactor::class, static fn (): PayloadRedactor => new class implements PayloadRedactor
        {
            public function redact(array $payload): array
            {
                foreach ($payload as $key => $value) {
                    if (is_string($value)) {
                        $payload[$key] = str_replace('custom-secret', '[custom-redacted]', $value);
                    }
                }

                return $payload;
            }
        });
        $this->app->forgetInstance(FlowStore::class);
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.custom-redactor')
            ->step('charge', CustomSecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.custom-redactor', []);

        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertStringNotContainsString('custom-secret', (string) $failedStep->error_message);
        $this->assertStringContainsString('[custom-redacted]', (string) $failedStep->error_message);
    }

    public function test_persisted_error_messages_redact_normalized_key_variants(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.redaction.keys', ['apikey', 'authorization']);
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.normalized-redaction')
            ->step('charge', SecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.normalized-redaction', []);

        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertStringNotContainsString('camel-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('dash-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('auth-secret', (string) $failedStep->error_message);
    }

    public function test_engine_does_not_write_when_persistence_is_disabled(): void
    {
        $this->migrateFlowTables();

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persist.disabled')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.persist.disabled', []);

        $this->assertPersistenceTablesEmpty();
    }

    public function test_dry_run_does_not_write_even_when_persistence_is_enabled(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.dry')
            ->step('plain', AlwaysSucceedsHandler::class)
            ->step('aware', DryRunAwareHandler::class)
            ->withDryRun(true)
            ->register();

        $run = $engine->dryRun('flow.persist.dry', []);

        $this->assertTrue($run->dryRun);
        $this->assertPersistenceTablesEmpty();
    }

    public function test_audit_disabled_suppresses_events_and_persisted_audit_rows_only(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.audit_trail_enabled', false);
        Event::fake([FlowStepStarted::class, FlowStepCompleted::class, FlowStepFailed::class, FlowCompensated::class]);
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.audit-disabled')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.persist.audit-disabled', []);

        $this->assertSame(1, FlowRunRecord::query()->count());
        $this->assertSame(1, FlowStepRecord::query()->count());
        $this->assertSame(0, FlowAuditRecord::query()->count());
        Event::assertNotDispatched(FlowStepStarted::class);
        Event::assertNotDispatched(FlowStepCompleted::class);
        Event::assertNotDispatched(FlowStepFailed::class);
        Event::assertNotDispatched(FlowCompensated::class);
    }

    public function test_persisted_run_is_closed_when_a_step_listener_throws(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->app['events']->listen(
            FlowStepStarted::class,
            static fn (): never => throw new RuntimeException('listener exploded'),
        );

        $engine->define('flow.persist.listener')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.listener', []);
            $this->fail('The throwing listener should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('listener exploded', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()->first();
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_FAILED, $runRecord->status);
        $this->assertSame('create', $runRecord->failed_step);
        $this->assertNotNull($runRecord->finished_at);

        $stepRecord = FlowStepRecord::query()->first();
        $this->assertInstanceOf(FlowStepRecord::class, $stepRecord);
        $this->assertSame('failed', $stepRecord->status);
        $this->assertSame(RuntimeException::class, $stepRecord->error_class);
        $this->assertSame('listener exploded', $stepRecord->error_message);

        $failedAudit = FlowAuditRecord::query()
            ->where('run_id', $runRecord->id)
            ->where('event', 'FlowStepFailed')
            ->first();

        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertSame('FlowStepStarted', $failedAudit->payload['listener_event']);
    }

    public function test_persisted_completed_listener_failure_is_recorded_as_failed_transition(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->app['events']->listen(
            FlowStepCompleted::class,
            static fn (): never => throw new RuntimeException('listener token=plain-secret'),
        );

        $engine->define('flow.persist.completed-listener')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->register();

        try {
            $engine->execute('flow.persist.completed-listener', []);
            $this->fail('The throwing listener should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('listener token=plain-secret', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()->first();
        $stepRecord = FlowStepRecord::query()->first();
        $failedAudit = FlowAuditRecord::query()
            ->where('event', 'FlowStepFailed')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $stepRecord);
        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertTrue($runRecord->compensated);
        $this->assertSame('failed', $stepRecord->status);
        $this->assertSame(RuntimeException::class, $stepRecord->error_class);
        $this->assertStringNotContainsString('plain-secret', (string) $stepRecord->error_message);
        $this->assertSame('FlowStepCompleted', $failedAudit->payload['listener_event']);
        $this->assertStringNotContainsString('plain-secret', (string) $failedAudit->payload['error_message']);
        $this->assertCount(1, RecordingCompensator::$invocations);
        $this->assertSame(AlwaysSucceedsHandler::class, RecordingCompensator::$invocations[0]['originalOutput']['handler']);
    }

    public function test_persisted_failed_listener_failure_does_not_duplicate_failed_transition_audit(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->app['events']->listen(
            FlowStepFailed::class,
            static fn (): never => throw new RuntimeException('failed listener exploded'),
        );

        $engine->define('flow.persist.failed-listener')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.failed-listener', []);
            $this->fail('The throwing failed-step listener should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed listener exploded', $exception->getMessage());
        }

        $failedAudits = FlowAuditRecord::query()
            ->where('event', 'FlowStepFailed')
            ->where('step_name', 'charge')
            ->get();

        $this->assertCount(1, $failedAudits);
        $this->assertCount(1, RecordingCompensator::$invocations);
    }

    public function test_persisted_compensation_listener_failure_does_not_abort_rollback(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->app['events']->listen(
            FlowCompensated::class,
            static fn (): never => throw new RuntimeException('compensation token=plain-secret'),
        );

        $engine->define('flow.persist.compensation-listener')
            ->step('one', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('two', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('fail', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.compensation-listener', []);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $listenerFailureAudits = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowCompensated')
            ->get()
            ->filter(static fn (FlowAuditRecord $record): bool => ($record->payload['listener_failed'] ?? false) === true)
            ->values();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertCount(2, RecordingCompensator::$invocations);
        $this->assertCount(2, $listenerFailureAudits);
        $this->assertStringNotContainsString(
            'plain-secret',
            (string) $listenerFailureAudits[0]->payload['listener_error_message'],
        );
    }

    public function test_success_transition_persistence_failure_compensates_before_throwing(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingAudit('FlowStepCompleted');

        $engine->define('flow.persist.success-audit-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->register();

        try {
            $engine->execute('flow.persist.success-audit-down', []);
            $this->fail('The failing audit repository should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit down for FlowStepCompleted', $exception->getMessage());
        }

        $stepRecord = FlowStepRecord::query()
            ->where('step_name', 'create')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $stepRecord);
        $this->assertSame('failed', $stepRecord->status);
        $this->assertCount(1, RecordingCompensator::$invocations);
        $this->assertSame(
            AlwaysSucceedsHandler::class,
            RecordingCompensator::$invocations[0]['stepOutputs']['create']['handler'],
        );
    }

    public function test_failed_transition_persistence_failure_still_compensates_completed_steps(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingAudit('FlowStepFailed');

        $engine->define('flow.persist.failed-audit-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.failed-audit-down', []);
            $this->fail('The failing audit repository should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit down for FlowStepFailed', $exception->getMessage());
        }

        $failedStep = FlowStepRecord::query()
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertSame('failed', $failedStep->status);
        $this->assertCount(1, RecordingCompensator::$invocations);
    }

    public function test_failed_transition_run_update_failure_keeps_failed_step_audit_context(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingRunUpdateStatus(FlowRun::STATUS_FAILED);

        $engine->define('flow.persist.failed-run-update-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.failed-run-update-down', []);
            $this->fail('The failing run repository should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('run update down for failed', $exception->getMessage());
        }

        $failedAudit = FlowAuditRecord::query()
            ->where('event', 'FlowStepFailed')
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertTrue($failedAudit->payload['runtime_abort_recovery']);
        $this->assertCount(1, RecordingCompensator::$invocations);
    }

    public function test_compensation_audit_persistence_failure_does_not_abort_rollback(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingAudit('FlowCompensated');

        $engine->define('flow.persist.compensation-audit-down')
            ->step('one', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('two', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->step('fail', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.compensation-audit-down', []);

        $this->assertSame(FlowRun::STATUS_COMPENSATED, $run->status);
        $this->assertCount(2, RecordingCompensator::$invocations);
    }

    public function test_final_run_update_failure_compensates_completed_steps(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingRunUpdateStatus(FlowRun::STATUS_SUCCEEDED);

        $engine->define('flow.persist.final-run-update-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->register();

        try {
            $engine->execute('flow.persist.final-run-update-down', []);
            $this->fail('The failing run repository should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('run update down for succeeded', $exception->getMessage());
        }

        $this->assertCount(1, RecordingCompensator::$invocations);
    }

    public function test_final_run_update_failure_marks_run_aborted_when_no_compensators_exist(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingRunUpdateStatus(FlowRun::STATUS_SUCCEEDED);

        $engine->define('flow.persist.final-run-update-down-no-compensator')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.final-run-update-down-no-compensator', []);
            $this->fail('The failing run repository should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('run update down for succeeded', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_ABORTED, $runRecord->status);
        $this->assertNull($runRecord->failed_step);
    }

    public function test_compensation_exception_is_not_masked_by_failed_run_finish_persistence_failure(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingRunUpdateStatusOnAttempt(FlowRun::STATUS_FAILED, 2);

        $engine->define('flow.persist.compensation-and-run-finish-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(ThrowingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.compensation-and-run-finish-down', []);
            $this->fail('The throwing compensator should abort execution.');
        } catch (FlowCompensationException $exception) {
            $this->assertStringContainsString('compensation completed with 1 failed compensator', $exception->getMessage());
        }
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }

    private function engineWithFailingAudit(string $event): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        /** @var FlowStore $inner */
        $inner = $this->app->make(FlowStore::class);
        $store = new class($inner, $event) implements FlowStore
        {
            public function __construct(
                private readonly FlowStore $inner,
                private readonly string $event,
            ) {}

            public function runs(): RunRepository
            {
                return $this->inner->runs();
            }

            public function steps(): StepRunRepository
            {
                return $this->inner->steps();
            }

            public function audit(): AuditRepository
            {
                return new class($this->inner->audit(), $this->event) implements AuditRepository
                {
                    public function __construct(
                        private readonly AuditRepository $inner,
                        private readonly string $event,
                    ) {}

                    public function append(
                        string $runId,
                        string $event,
                        array $payload = [],
                        ?string $stepName = null,
                        ?array $businessImpact = null,
                        ?DateTimeInterface $occurredAt = null,
                    ): FlowAuditRecord {
                        if ($event === $this->event) {
                            throw new RuntimeException('audit down for '.$event);
                        }

                        return $this->inner->append(
                            $runId,
                            $event,
                            $payload,
                            $stepName,
                            $businessImpact,
                            $occurredAt,
                        );
                    }

                    public function forRun(string $runId): Collection
                    {
                        return $this->inner->forRun($runId);
                    }
                };
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };

        /** @var array<string, mixed> $config */
        $config = $this->app['config']->get('laravel-flow');

        return new FlowEngine($this->app, $this->app['events'], $config, $store);
    }

    private function engineWithFailingRunUpdateStatus(string $status): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        /** @var FlowStore $inner */
        $inner = $this->app->make(FlowStore::class);
        $store = new class($inner, $status) implements FlowStore
        {
            public function __construct(
                private readonly FlowStore $inner,
                private readonly string $status,
            ) {}

            public function runs(): RunRepository
            {
                return new class($this->inner->runs(), $this->status) implements RunRepository
                {
                    public function __construct(
                        private readonly RunRepository $inner,
                        private readonly string $status,
                    ) {}

                    public function create(array $attributes): FlowRunRecord
                    {
                        return $this->inner->create($attributes);
                    }

                    public function update(string $runId, array $attributes): FlowRunRecord
                    {
                        if (($attributes['status'] ?? null) === $this->status) {
                            throw new RuntimeException('run update down for '.$this->status);
                        }

                        return $this->inner->update($runId, $attributes);
                    }

                    public function find(string $runId): ?FlowRunRecord
                    {
                        return $this->inner->find($runId);
                    }

                    public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord
                    {
                        return $this->inner->findByIdempotencyKey($idempotencyKey);
                    }
                };
            }

            public function steps(): StepRunRepository
            {
                return $this->inner->steps();
            }

            public function audit(): AuditRepository
            {
                return $this->inner->audit();
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };

        /** @var array<string, mixed> $config */
        $config = $this->app['config']->get('laravel-flow');

        return new FlowEngine($this->app, $this->app['events'], $config, $store);
    }

    private function engineWithFailingRunUpdateStatusOnAttempt(string $status, int $attempt): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        /** @var FlowStore $inner */
        $inner = $this->app->make(FlowStore::class);
        $runRepository = new class($inner->runs(), $status, $attempt) implements RunRepository
        {
            private int $seen = 0;

            public function __construct(
                private readonly RunRepository $inner,
                private readonly string $status,
                private readonly int $attempt,
            ) {}

            public function create(array $attributes): FlowRunRecord
            {
                return $this->inner->create($attributes);
            }

            public function update(string $runId, array $attributes): FlowRunRecord
            {
                if (($attributes['status'] ?? null) === $this->status) {
                    $this->seen++;

                    if ($this->seen === $this->attempt) {
                        throw new RuntimeException('run update down for '.$this->status.' on attempt '.$this->attempt);
                    }
                }

                return $this->inner->update($runId, $attributes);
            }

            public function find(string $runId): ?FlowRunRecord
            {
                return $this->inner->find($runId);
            }

            public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord
            {
                return $this->inner->findByIdempotencyKey($idempotencyKey);
            }
        };

        $store = new class($inner, $runRepository) implements FlowStore
        {
            public function __construct(
                private readonly FlowStore $inner,
                private readonly RunRepository $runRepository,
            ) {}

            public function runs(): RunRepository
            {
                return $this->runRepository;
            }

            public function steps(): StepRunRepository
            {
                return $this->inner->steps();
            }

            public function audit(): AuditRepository
            {
                return $this->inner->audit();
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };

        /** @var array<string, mixed> $config */
        $config = $this->app['config']->get('laravel-flow');

        return new FlowEngine($this->app, $this->app['events'], $config, $store);
    }

    private function assertPersistenceTablesEmpty(): void
    {
        $this->assertSame(0, FlowRunRecord::query()->count());
        $this->assertSame(0, FlowStepRecord::query()->count());
        $this->assertSame(0, FlowAuditRecord::query()->count());
    }
}
