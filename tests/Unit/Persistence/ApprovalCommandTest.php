<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ApprovalPayloadCapturingHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;

final class ApprovalCommandTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
        ApprovalPayloadCapturingHandler::reset();
        RecordingCompensator::reset();
    }

    public function test_approve_command_resumes_paused_run_with_payload_and_actor_metadata(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.approval-cli-approve')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', ApprovalPayloadCapturingHandler::class)
            ->register();

        $pausedRun = $engine->execute('flow.persist.approval-cli-approve', []);
        $token = $pausedRun->approvalTokens['manager']->plainTextToken;

        $this->artisan('flow:approve', [
            'token' => $token,
            '--actor' => json_encode(['user_id' => 42], JSON_THROW_ON_ERROR),
            '--payload' => json_encode(['decision' => 'ship'], JSON_THROW_ON_ERROR),
        ])
            ->expectsOutputToContain(sprintf('Approved flow run [%s] with status [succeeded].', $pausedRun->id))
            ->assertExitCode(0);

        $this->assertSame(1, ApprovalPayloadCapturingHandler::$callCount);
        $this->assertSame('ship', ApprovalPayloadCapturingHandler::$lastStepOutputs['manager']['approval_payload']['decision']);
        $this->assertSame(42, ApprovalPayloadCapturingHandler::$lastStepOutputs['manager']['approval_actor']['user_id']);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, FlowRunRecord::query()
            ->whereKey($pausedRun->id)
            ->firstOrFail()
            ->status);
        $this->assertSame(FlowApprovalRecord::STATUS_APPROVED, FlowApprovalRecord::query()
            ->where('run_id', $pausedRun->id)
            ->where('step_name', 'manager')
            ->firstOrFail()
            ->status);
    }

    public function test_reject_command_rejects_paused_run_and_compensates(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.approval-cli-reject')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->approvalGate('manager')
            ->step('publish', ApprovalPayloadCapturingHandler::class)
            ->register();

        $pausedRun = $engine->execute('flow.persist.approval-cli-reject', []);
        $token = $pausedRun->approvalTokens['manager']->plainTextToken;

        $this->artisan('flow:reject', [
            'token' => $token,
            '--actor' => json_encode(['user_id' => 456], JSON_THROW_ON_ERROR),
            '--payload' => json_encode(['reason' => 'duplicate'], JSON_THROW_ON_ERROR),
        ])
            ->expectsOutputToContain(sprintf('Rejected flow run [%s] with status [compensated].', $pausedRun->id))
            ->assertExitCode(0);

        $this->assertSame(0, ApprovalPayloadCapturingHandler::$callCount);
        $this->assertCount(1, RecordingCompensator::$invocations);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, FlowRunRecord::query()
            ->whereKey($pausedRun->id)
            ->firstOrFail()
            ->status);
        $approvalRecord = FlowApprovalRecord::query()
            ->where('run_id', $pausedRun->id)
            ->where('step_name', 'manager')
            ->firstOrFail();
        $this->assertSame(FlowApprovalRecord::STATUS_REJECTED, $approvalRecord->status);
        $this->assertSame('duplicate', $approvalRecord->payload['reason']);
        $this->assertSame(456, $approvalRecord->actor['user_id']);
    }

    public function test_approve_command_rejects_invalid_payload_json(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.approval-cli-invalid-json')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $pausedRun = $engine->execute('flow.persist.approval-cli-invalid-json', []);
        $token = $pausedRun->approvalTokens['manager']->plainTextToken;

        $this->artisan('flow:approve', [
            'token' => $token,
            '--payload' => '{not-json',
        ])
            ->expectsOutputToContain('Approval option --payload must contain valid JSON object or array.')
            ->assertExitCode(1);

        $this->assertSame(FlowApprovalRecord::STATUS_PENDING, FlowApprovalRecord::query()
            ->where('run_id', $pausedRun->id)
            ->where('step_name', 'manager')
            ->firstOrFail()
            ->status);
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }
}
