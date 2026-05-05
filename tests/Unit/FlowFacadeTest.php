<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowDefinitionBuilder;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;

final class FlowFacadeTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
        RecordingCompensator::reset();
    }

    public function test_define_returns_a_builder(): void
    {
        $this->assertInstanceOf(FlowDefinitionBuilder::class, Flow::define('flow.facade.def'));
    }

    public function test_full_round_trip_define_execute_via_facade(): void
    {
        Flow::define('flow.facade.full')
            ->withInput(['name'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = Flow::execute('flow.facade.full', ['name' => 'lorenzo']);

        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
    }

    public function test_dry_run_via_facade_marks_run_as_dry(): void
    {
        Flow::define('flow.facade.dry')
            ->step('aware', DryRunAwareHandler::class)
            ->withDryRun(true)
            ->register();

        $run = Flow::dryRun('flow.facade.dry', []);

        $this->assertTrue($run->dryRun);
        $this->assertSame(['projected_writes' => 0], $run->stepResults['aware']->businessImpact);
    }

    public function test_definitions_returned_via_facade_include_registered_flow(): void
    {
        Flow::define('flow.facade.list')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $defs = Flow::definitions();

        $this->assertArrayHasKey('flow.facade.list', $defs);
    }

    public function test_resume_via_facade_continues_persisted_approval_run(): void
    {
        $this->enableFacadePersistence();

        Flow::define('flow.facade.resume')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $pausedRun = Flow::execute('flow.facade.resume', []);
        $token = $pausedRun->approvalTokens['manager']->plainTextToken;

        $resumedRun = Flow::resume($token, ['decision' => 'ship'], ['user_id' => 123]);

        $this->assertSame($pausedRun->id, $resumedRun->id);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $resumedRun->status);
        $this->assertSame(2, AlwaysSucceedsHandler::$callCount);
    }

    public function test_reject_via_facade_compensates_persisted_approval_run(): void
    {
        $this->enableFacadePersistence();

        Flow::define('flow.facade.reject')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->approvalGate('manager')
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $pausedRun = Flow::execute('flow.facade.reject', []);
        $token = $pausedRun->approvalTokens['manager']->plainTextToken;

        $rejectedRun = Flow::reject($token, ['reason' => 'duplicate'], ['user_id' => 456]);

        $this->assertSame($pausedRun->id, $rejectedRun->id);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $rejectedRun->status);
        $this->assertSame('manager', $rejectedRun->failedStep);
        $this->assertCount(1, RecordingCompensator::$invocations);
    }

    private function enableFacadePersistence(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);
        Flow::clearResolvedInstance(FlowEngine::class);
    }
}
