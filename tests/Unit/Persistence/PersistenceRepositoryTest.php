<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use RuntimeException;

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

    public function test_flow_store_rolls_back_repository_operations_inside_transactions(): void
    {
        $this->migrateFlowTables();

        $store = $this->app->make(FlowStore::class);

        try {
            $store->transaction(function () use ($store): void {
                $store->runs()->create([
                    'definition_name' => 'checkout.rollback',
                    'dry_run' => false,
                    'id' => '00000000-0000-4000-8000-000000000007',
                    'input' => [],
                    'status' => FlowRun::STATUS_PENDING,
                ]);

                throw new RuntimeException('rollback expected');
            });

            $this->fail('The transaction callback should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback expected', $exception->getMessage());
        }

        $this->assertNull($store->runs()->find('00000000-0000-4000-8000-000000000007'));
    }

    public function test_repositories_do_not_mutate_identity_fields_from_attribute_payloads(): void
    {
        $this->migrateFlowTables();

        $runs = $this->app->make(RunRepository::class);
        $steps = $this->app->make(StepRunRepository::class);
        $startedAt = new DateTimeImmutable('2026-05-02 08:00:00');

        $run = $runs->create([
            'correlation_id' => 'corr-original',
            'definition_name' => 'identity.safe',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000004',
            'idempotency_key' => 'identity-original',
            'input' => ['original' => true],
            'started_at' => $startedAt,
            'status' => FlowRun::STATUS_PENDING,
        ]);

        $updated = $runs->update($run->id, [
            'correlation_id' => 'corr-mutated',
            'definition_name' => 'identity.mutated',
            'dry_run' => true,
            'id' => '00000000-0000-4000-8000-000000009999',
            'idempotency_key' => 'identity-mutated',
            'input' => ['mutated' => true],
            'started_at' => new DateTimeImmutable('2026-05-02 09:00:00'),
            'status' => FlowRun::STATUS_RUNNING,
        ]);

        $this->assertSame($run->id, $updated->id);
        $this->assertSame('identity.safe', $updated->definition_name);
        $this->assertFalse($updated->dry_run);
        $this->assertSame(['original' => true], $updated->input);
        $this->assertSame('corr-original', $updated->correlation_id);
        $this->assertSame('identity-original', $updated->idempotency_key);
        $this->assertSame($startedAt->getTimestamp(), $updated->started_at->getTimestamp());
        $this->assertSame(FlowRun::STATUS_RUNNING, $updated->status);
        $this->assertNull($runs->find('00000000-0000-4000-8000-000000009999'));
        $this->assertNull($runs->findByIdempotencyKey('identity-mutated'));

        $step = $steps->createOrUpdate($run->id, 'charge', [
            'run_id' => '00000000-0000-4000-8000-000000009999',
            'sequence' => 1,
            'status' => 'running',
            'step_name' => 'ship',
        ]);

        $this->assertSame($run->id, $step->run_id);
        $this->assertSame('charge', $step->step_name);
        $this->assertCount(1, $steps->forRun($run->id));
    }

    public function test_step_create_or_update_uses_atomic_upsert_for_step_identity(): void
    {
        $this->migrateFlowTables();

        $runs = $this->app->make(RunRepository::class);
        $steps = $this->app->make(StepRunRepository::class);

        $run = $runs->create([
            'definition_name' => 'atomic.steps',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000006',
            'input' => [],
            'status' => FlowRun::STATUS_PENDING,
        ]);

        Date::setTestNow(Carbon::parse('2026-05-02 10:00:00'));

        try {
            DB::connection()->enableQueryLog();

            $step = $steps->createOrUpdate($run->id, 'reserve-stock', [
                'output' => ['token' => 'runtime-token'],
                'sequence' => 1,
                'status' => 'running',
            ]);

            $queries = DB::connection()->getQueryLog();
            DB::connection()->flushQueryLog();

            $this->assertTrue(
                collect($queries)->contains(
                    fn (array $query): bool => str_contains(strtolower((string) $query['query']), ' on conflict '),
                ),
                'Step persistence should use a database-level upsert instead of select-before-insert.',
            );

            Date::setTestNow(Carbon::parse('2026-05-02 10:00:05'));

            $updatedStep = $steps->createOrUpdate($run->id, 'reserve-stock', [
                'output' => ['token' => 'new-runtime-token'],
                'sequence' => 1,
                'status' => 'succeeded',
            ]);
        } finally {
            Date::setTestNow();
        }

        $this->assertSame($step->id, $updatedStep->id);
        $this->assertSame('succeeded', $updatedStep->status);
        $this->assertSame('[redacted]', $updatedStep->output['token']);
        $this->assertNotNull($step->created_at);
        $this->assertNotNull($step->updated_at);
        $this->assertSame($step->created_at->getTimestamp(), $updatedStep->created_at->getTimestamp());
        $this->assertGreaterThan($step->updated_at->getTimestamp(), $updatedStep->updated_at->getTimestamp());
        $this->assertCount(1, $steps->forRun($run->id));
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

    public function test_audit_records_reject_query_builder_mutations(): void
    {
        $this->migrateFlowTables();

        $audit = $this->app->make(AuditRepository::class);
        $record = $audit->append(
            runId: '00000000-0000-4000-8000-000000000005',
            event: 'FlowStepStarted',
            payload: [],
        );

        try {
            FlowAuditRecord::query()
                ->whereKey($record->id)
                ->update(['event' => 'FlowStepCompleted']);
            $this->fail('Updating audit records through the query builder should throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Flow audit records are append-only and cannot be updated.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Flow audit records are append-only and cannot be deleted.');

        FlowAuditRecord::query()
            ->whereKey($record->id)
            ->delete();
    }
}
