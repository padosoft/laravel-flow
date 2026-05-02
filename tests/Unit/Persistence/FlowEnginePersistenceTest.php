<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;
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

        $engine->define('flow.persist.success')
            ->withInput(['token'])
            ->step('create', AlwaysSucceedsHandler::class)
            ->step('impact', DryRunAwareHandler::class)
            ->withDryRun(true)
            ->register();

        $run = $engine->execute('flow.persist.success', [
            'safe' => 'visible',
            'token' => 'plain-secret',
        ]);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $runRecord->status);
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
