<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use DateTimeImmutable;
use LogicException;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\FlowRun;

final class PersistenceRepositoryTest extends PersistenceTestCase
{
    public function test_repositories_store_redacted_run_step_and_audit_records(): void
    {
        $this->migrateFlowTables();

        $runs = $this->app->make(RunRepository::class);
        $steps = $this->app->make(StepRunRepository::class);
        $audit = $this->app->make(AuditRepository::class);

        $run = $runs->create([
            'definition_name' => 'promotion.create',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000001',
            'idempotency_key' => 'promo-1',
            'input' => [
                'password' => 'plain-secret',
                'safe' => 'visible',
                'tenant' => ['api_key' => 'tenant-key'],
            ],
            'started_at' => new DateTimeImmutable('2026-05-02 10:00:00'),
            'status' => FlowRun::STATUS_PENDING,
        ]);

        $this->assertSame('[redacted]', $run->input['password']);
        $this->assertSame('[redacted]', $run->input['tenant']['api_key']);
        $this->assertSame('visible', $run->input['safe']);
        $this->assertSame($run->id, $runs->findByIdempotencyKey('promo-1')?->id);

        $updatedRun = $runs->update($run->id, [
            'output' => ['token' => 'runtime-token', 'ok' => true],
            'status' => FlowRun::STATUS_SUCCEEDED,
        ]);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $updatedRun->status);
        $this->assertSame('[redacted]', $updatedRun->output['token']);
        $this->assertTrue($updatedRun->output['ok']);

        $step = $steps->createOrUpdate($run->id, 'simulate', [
            'business_impact' => ['projected_revenue_eur' => 18900],
            'handler' => 'Tests\\SimulatePromotionImpact',
            'output' => ['authorization' => 'Bearer abc'],
            'sequence' => 1,
            'status' => 'succeeded',
        ]);

        $this->assertSame('[redacted]', $step->output['authorization']);
        $this->assertSame(18900, $step->business_impact['projected_revenue_eur']);
        $this->assertCount(1, $steps->forRun($run->id));

        $auditRecord = $audit->append(
            runId: $run->id,
            event: 'FlowStepCompleted',
            payload: ['authorization' => 'Bearer abc', 'status' => 'succeeded'],
            stepName: 'simulate',
            businessImpact: ['secret' => 'hidden', 'safe' => 'visible'],
        );

        $this->assertSame('[redacted]', $auditRecord->payload['authorization']);
        $this->assertSame('[redacted]', $auditRecord->business_impact['secret']);
        $this->assertSame('visible', $auditRecord->business_impact['safe']);
        $this->assertCount(1, $audit->forRun($run->id));
    }

    public function test_flow_store_runs_repository_operations_inside_transactions(): void
    {
        $this->migrateFlowTables();

        $store = $this->app->make(FlowStore::class);

        $run = $store->transaction(function () use ($store) {
            return $store->runs()->create([
                'definition_name' => 'checkout',
                'dry_run' => false,
                'id' => '00000000-0000-4000-8000-000000000002',
                'input' => [],
                'status' => FlowRun::STATUS_PENDING,
            ]);
        });

        $this->assertSame('checkout', $run->definition_name);
    }

    public function test_audit_records_are_append_only(): void
    {
        $this->migrateFlowTables();

        $audit = $this->app->make(AuditRepository::class);
        $record = $audit->append(
            runId: '00000000-0000-4000-8000-000000000003',
            event: 'FlowStepStarted',
            payload: [],
        );

        try {
            $record->event = 'FlowStepCompleted';
            $record->save();
            $this->fail('Updating an existing audit record should throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Flow audit records are append-only and cannot be updated.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Flow audit records are append-only and cannot be deleted.');

        $record->delete();
    }
}
