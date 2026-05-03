<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ClockAdvancingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecretFailsHandler;
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
        $this->assertStringNotContainsString('abc123', (string) $failedStep->error_message);
        $this->assertStringContainsString('[redacted]', (string) $failedStep->error_message);
        $this->assertSame($failedStep->error_message, $failedAudit->payload['error_message']);
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

    public function test_audit_disabled_suppresses_persisted_audit_rows_only(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.audit_trail_enabled', false);
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.audit-disabled')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.persist.audit-disabled', []);

        $this->assertSame(1, FlowRunRecord::query()->count());
        $this->assertSame(1, FlowStepRecord::query()->count());
        $this->assertSame(0, FlowAuditRecord::query()->count());
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
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }

    private function assertPersistenceTablesEmpty(): void
    {
        $this->assertSame(0, FlowRunRecord::query()->count());
        $this->assertSame(0, FlowStepRecord::query()->count());
        $this->assertSame(0, FlowAuditRecord::query()->count());
    }
}
