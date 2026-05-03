<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Persistence\EloquentAuditRepository;
use Padosoft\LaravelFlow\Persistence\EloquentRunRepository;
use Padosoft\LaravelFlow\Persistence\ExecutionScopedPayloadRedactor;
use Padosoft\LaravelFlow\Persistence\PayloadRedactorResolution;
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

    public function test_flow_store_uses_current_payload_redactor_binding_even_when_resolved_once(): void
    {
        $this->migrateFlowTables();
        $this->app->bind(PayloadRedactor::class, fn (): PayloadRedactor => $this->labelRedactor('first-redactor'));

        $firstStore = $this->app->make(FlowStore::class);

        $this->app->bind(PayloadRedactor::class, fn (): PayloadRedactor => $this->labelRedactor('second-redactor'));

        $secondStore = $this->app->make(FlowStore::class);
        $run = $secondStore->runs()->create([
            'definition_name' => 'flow.redactor.current',
            'dry_run' => false,
            'id' => 'run-current-redactor',
            'input' => ['token' => 'plain-secret'],
            'started_at' => new DateTimeImmutable('2026-05-02 11:00:00'),
            'status' => FlowRun::STATUS_RUNNING,
        ]);

        $this->assertSame($firstStore, $secondStore);
        $this->assertSame('second-redactor', $run->input['token']);
    }

    public function test_public_audit_repository_write_uses_one_payload_redactor_instance(): void
    {
        $this->migrateFlowTables();
        $counter = $this->bindCountingStringRedactor();

        $record = $this->app->make(AuditRepository::class)->append(
            runId: '00000000-0000-4000-8000-000000000009',
            event: 'FlowStepCompleted',
            payload: ['token' => 'payload-secret'],
            businessImpact: ['secret' => 'impact-secret'],
        );

        $this->assertSame(1, $counter->value);
        $this->assertSame('redactor-1', $record->payload['token']);
        $this->assertSame('redactor-1', $record->business_impact['secret']);
    }

    public function test_public_audit_repository_does_not_resolve_payload_redactor_for_empty_payloads(): void
    {
        $this->migrateFlowTables();
        $counter = new class
        {
            public int $value = 0;
        };

        $this->app->bind(PayloadRedactor::class, static function () use ($counter): PayloadRedactor {
            $counter->value++;

            return new class implements PayloadRedactor
            {
                public function redact(array $payload): array
                {
                    return $payload;
                }
            };
        });

        $record = $this->app->make(AuditRepository::class)->append(
            runId: '00000000-0000-4000-8000-000000000014',
            event: 'FlowStepStarted',
            payload: [],
            businessImpact: null,
        );

        $this->assertSame(0, $counter->value);
        $this->assertSame([], $record->payload);
        $this->assertNull($record->business_impact);
    }

    public function test_public_run_repository_write_uses_one_payload_redactor_instance_for_multiple_json_fields(): void
    {
        $this->migrateFlowTables();
        $counter = $this->bindCountingStringRedactor();
        $runs = $this->app->make(RunRepository::class);

        $run = $runs->create([
            'definition_name' => 'flow.redactor.run',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000010',
            'input' => ['token' => 'input-secret'],
            'started_at' => new DateTimeImmutable('2026-05-02 11:00:00'),
            'status' => FlowRun::STATUS_RUNNING,
        ]);
        $counter->value = 0;

        $updated = $runs->update($run->id, [
            'business_impact' => ['secret' => 'impact-secret'],
            'output' => ['token' => 'output-secret'],
            'status' => FlowRun::STATUS_SUCCEEDED,
        ]);

        $this->assertSame(1, $counter->value);
        $this->assertSame('redactor-1', $updated->business_impact['secret']);
        $this->assertSame('redactor-1', $updated->output['token']);
    }

    public function test_public_step_repository_write_uses_one_payload_redactor_instance_for_multiple_json_fields(): void
    {
        $this->migrateFlowTables();
        $counter = $this->bindCountingStringRedactor();
        $runs = $this->app->make(RunRepository::class);
        $steps = $this->app->make(StepRunRepository::class);

        $run = $runs->create([
            'definition_name' => 'flow.redactor.step',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000011',
            'input' => [],
            'started_at' => new DateTimeImmutable('2026-05-02 11:00:00'),
            'status' => FlowRun::STATUS_RUNNING,
        ]);
        $counter->value = 0;

        $step = $steps->createOrUpdate($run->id, 'charge', [
            'business_impact' => ['secret' => 'impact-secret'],
            'handler' => 'Tests\\Charge',
            'input' => ['token' => 'input-secret'],
            'output' => ['authorization' => 'output-secret'],
            'sequence' => 1,
            'status' => 'succeeded',
        ]);

        $this->assertSame(1, $counter->value);
        $this->assertSame('redactor-1', $step->business_impact['secret']);
        $this->assertSame('redactor-1', $step->input['token']);
        $this->assertSame('redactor-1', $step->output['authorization']);
    }

    public function test_public_repository_redaction_preserves_each_json_payload_shape_for_custom_redactors(): void
    {
        $this->migrateFlowTables();
        $this->app->bind(PayloadRedactor::class, fn (): PayloadRedactor => $this->topLevelSecretRedactor());

        $runs = $this->app->make(RunRepository::class);
        $steps = $this->app->make(StepRunRepository::class);
        $audit = $this->app->make(AuditRepository::class);

        $run = $runs->create([
            'definition_name' => 'flow.redactor.shape',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000012',
            'input' => ['token' => 'input-secret'],
            'started_at' => new DateTimeImmutable('2026-05-02 11:00:00'),
            'status' => FlowRun::STATUS_RUNNING,
        ]);

        $updated = $runs->update($run->id, [
            'business_impact' => ['secret' => 'impact-secret'],
            'output' => ['authorization' => 'output-secret'],
            'status' => FlowRun::STATUS_SUCCEEDED,
        ]);
        $step = $steps->createOrUpdate($run->id, 'charge', [
            'business_impact' => ['secret' => 'step-impact-secret'],
            'handler' => 'Tests\\Charge',
            'input' => ['token' => 'step-input-secret'],
            'output' => ['authorization' => 'step-output-secret'],
            'sequence' => 1,
            'status' => 'succeeded',
        ]);
        $auditRecord = $audit->append(
            runId: $run->id,
            event: 'FlowStepCompleted',
            payload: ['authorization' => 'audit-payload-secret'],
            businessImpact: ['secret' => 'audit-impact-secret'],
        );

        $this->assertSame('[top-level-redacted]', $run->input['token']);
        $this->assertSame('[top-level-redacted]', $updated->business_impact['secret']);
        $this->assertSame('[top-level-redacted]', $updated->output['authorization']);
        $this->assertSame('[top-level-redacted]', $step->business_impact['secret']);
        $this->assertSame('[top-level-redacted]', $step->input['token']);
        $this->assertSame('[top-level-redacted]', $step->output['authorization']);
        $this->assertSame('[top-level-redacted]', $auditRecord->payload['authorization']);
        $this->assertSame('[top-level-redacted]', $auditRecord->business_impact['secret']);
    }

    public function test_repository_redaction_uses_current_redactor_provider_contract_for_decorators(): void
    {
        $this->migrateFlowTables();
        $counter = new class
        {
            public int $currentCalls = 0;
        };
        $innerProvider = new class($this->topLevelSecretRedactor(), $counter) implements CurrentPayloadRedactorProvider
        {
            public function __construct(
                private readonly PayloadRedactor $inner,
                private readonly object $counter,
            ) {}

            public function currentRedactor(): PayloadRedactor
            {
                $this->counter->currentCalls++;

                return $this->inner;
            }

            public function redact(array $payload): array
            {
                return $this->inner->redact($payload);
            }
        };
        $provider = new class($innerProvider, $counter) implements CurrentPayloadRedactorProvider
        {
            public function __construct(
                private readonly CurrentPayloadRedactorProvider $inner,
                private readonly object $counter,
            ) {}

            public function currentRedactor(): PayloadRedactor
            {
                $this->counter->currentCalls++;

                return $this->inner;
            }

            public function redact(array $payload): array
            {
                return $this->inner->redact($payload);
            }
        };
        $runs = new EloquentRunRepository(null, $provider);
        $audit = new EloquentAuditRepository(null, $provider);

        $run = $runs->create([
            'definition_name' => 'flow.redactor.decorator',
            'dry_run' => false,
            'id' => '00000000-0000-4000-8000-000000000013',
            'input' => ['token' => 'input-secret'],
            'started_at' => new DateTimeImmutable('2026-05-02 11:00:00'),
            'status' => FlowRun::STATUS_RUNNING,
        ]);
        $counter->currentCalls = 0;

        $updated = $runs->update($run->id, [
            'business_impact' => ['secret' => 'impact-secret'],
            'output' => ['authorization' => 'output-secret'],
            'status' => FlowRun::STATUS_SUCCEEDED,
        ]);
        $auditRecord = $audit->append(
            runId: $run->id,
            event: 'FlowStepCompleted',
            payload: ['authorization' => 'audit-payload-secret'],
            businessImpact: ['secret' => 'audit-impact-secret'],
        );

        $this->assertSame(4, $counter->currentCalls);
        $this->assertSame('[top-level-redacted]', $updated->business_impact['secret']);
        $this->assertSame('[top-level-redacted]', $updated->output['authorization']);
        $this->assertSame('[top-level-redacted]', $auditRecord->payload['authorization']);
        $this->assertSame('[top-level-redacted]', $auditRecord->business_impact['secret']);
    }

    public function test_execution_scoped_payload_redactor_falls_back_when_bound_to_payload_redactor_contract(): void
    {
        /** @var ExecutionScopedPayloadRedactor $scope */
        $scope = $this->app->make(ExecutionScopedPayloadRedactor::class);

        $this->app->instance(PayloadRedactor::class, $scope);
        $redacted = $scope->redact(['token' => 'plain-secret']);

        $this->assertSame('[redacted]', $redacted['token']);
    }

    public function test_execution_scoped_payload_redactor_falls_back_when_payload_contract_returns_fresh_scope(): void
    {
        /** @var ExecutionScopedPayloadRedactor $scope */
        $scope = $this->app->make(ExecutionScopedPayloadRedactor::class);

        $this->app->bind(
            PayloadRedactor::class,
            fn (): PayloadRedactor => new ExecutionScopedPayloadRedactor($this->app),
        );

        $redacted = $scope->redact(['token' => 'plain-secret']);

        $this->assertSame('[redacted]', $redacted['token']);
    }

    public function test_execution_scoped_payload_redactor_falls_back_when_decorator_returns_scope(): void
    {
        /** @var ExecutionScopedPayloadRedactor $scope */
        $scope = $this->app->make(ExecutionScopedPayloadRedactor::class);

        $this->app->instance(PayloadRedactor::class, new class($scope) implements CurrentPayloadRedactorProvider
        {
            public function __construct(
                private readonly ExecutionScopedPayloadRedactor $scope,
            ) {}

            public function currentRedactor(): PayloadRedactor
            {
                return $this->scope;
            }

            public function redact(array $payload): array
            {
                return $this->scope->redact($payload);
            }
        });

        $redacted = $scope->redact(['token' => 'plain-secret']);

        $this->assertSame('[redacted]', $redacted['token']);
    }

    public function test_current_payload_redactor_provider_cycles_fail_without_recursive_redaction(): void
    {
        $first = new class implements CurrentPayloadRedactorProvider
        {
            public CurrentPayloadRedactorProvider $next;

            public function currentRedactor(): PayloadRedactor
            {
                return $this->next;
            }

            public function redact(array $payload): array
            {
                return $payload;
            }
        };
        $second = new class implements CurrentPayloadRedactorProvider
        {
            public CurrentPayloadRedactorProvider $next;

            public function currentRedactor(): PayloadRedactor
            {
                return $this->next;
            }

            public function redact(array $payload): array
            {
                return $payload;
            }
        };
        $first->next = $second;
        $second->next = $first;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic CurrentPayloadRedactorProvider chain detected.');

        PayloadRedactorResolution::current($first);
    }

    public function test_fresh_current_payload_redactor_provider_cycles_fail_without_recursive_redaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic CurrentPayloadRedactorProvider chain detected.');

        PayloadRedactorResolution::current($this->freshProviderCycle());
    }

    public function test_execution_scoped_payload_redactor_fails_provider_cycles_without_recursive_redaction(): void
    {
        /** @var ExecutionScopedPayloadRedactor $scope */
        $scope = $this->app->make(ExecutionScopedPayloadRedactor::class);
        $first = new class implements CurrentPayloadRedactorProvider
        {
            public CurrentPayloadRedactorProvider $next;

            public function currentRedactor(): PayloadRedactor
            {
                return $this->next;
            }

            public function redact(array $payload): array
            {
                return $payload;
            }
        };
        $second = new class implements CurrentPayloadRedactorProvider
        {
            public CurrentPayloadRedactorProvider $next;

            public function currentRedactor(): PayloadRedactor
            {
                return $this->next;
            }

            public function redact(array $payload): array
            {
                return $payload;
            }
        };
        $first->next = $second;
        $second->next = $first;

        $this->app->instance(PayloadRedactor::class, $first);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic CurrentPayloadRedactorProvider chain detected.');

        $scope->redact(['token' => 'plain-secret']);
    }

    public function test_execution_scoped_payload_redactor_fails_fresh_provider_cycles_without_recursive_redaction(): void
    {
        /** @var ExecutionScopedPayloadRedactor $scope */
        $scope = $this->app->make(ExecutionScopedPayloadRedactor::class);

        $this->app->instance(PayloadRedactor::class, $this->freshProviderCycle());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic CurrentPayloadRedactorProvider chain detected.');

        $scope->redact(['token' => 'plain-secret']);
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
            DB::connection()->flushQueryLog();
            DB::connection()->disableQueryLog();
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

    public function test_audit_append_uses_laravel_clock_for_default_timestamps(): void
    {
        $this->migrateFlowTables();

        $audit = $this->app->make(AuditRepository::class);
        $frozen = Carbon::parse('2026-05-02 11:00:00');

        Date::setTestNow($frozen);

        try {
            $record = $audit->append(
                runId: '00000000-0000-4000-8000-000000000008',
                event: 'FlowStepStarted',
                payload: [],
            );
        } finally {
            Date::setTestNow();
        }

        $this->assertNotNull($record->created_at);
        $this->assertNotNull($record->occurred_at);
        $this->assertSame($frozen->getTimestamp(), $record->created_at->getTimestamp());
        $this->assertSame($record->created_at->getTimestamp(), $record->occurred_at->getTimestamp());
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

    private function labelRedactor(string $label): PayloadRedactor
    {
        return new class($label) implements PayloadRedactor
        {
            public function __construct(
                private readonly string $label,
            ) {}

            public function redact(array $payload): array
            {
                foreach ($payload as $key => $value) {
                    $payload[$key] = $this->redactValue($value);
                }

                return $payload;
            }

            private function redactValue(mixed $value): mixed
            {
                if (is_array($value)) {
                    foreach ($value as $key => $nested) {
                        $value[$key] = $this->redactValue($nested);
                    }

                    return $value;
                }

                return is_string($value) ? $this->label : $value;
            }
        };
    }

    private function topLevelSecretRedactor(): PayloadRedactor
    {
        return new class implements PayloadRedactor
        {
            /**
             * @param  array<string, mixed>  $payload
             * @return array<string, mixed>
             */
            public function redact(array $payload): array
            {
                foreach (['authorization', 'secret', 'token'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        $payload[$key] = '[top-level-redacted]';
                    }
                }

                return $payload;
            }
        };
    }

    private function freshProviderCycle(): CurrentPayloadRedactorProvider
    {
        $makeFirst = null;
        $makeSecond = null;

        $makeFirst = static function () use (&$makeSecond): CurrentPayloadRedactorProvider {
            return new class($makeSecond) implements CurrentPayloadRedactorProvider
            {
                public function __construct(
                    private readonly \Closure $next,
                ) {}

                public function currentRedactor(): PayloadRedactor
                {
                    return ($this->next)();
                }

                public function redact(array $payload): array
                {
                    return $payload;
                }
            };
        };
        $makeSecond = static function () use (&$makeFirst): CurrentPayloadRedactorProvider {
            return new class($makeFirst) implements CurrentPayloadRedactorProvider
            {
                public function __construct(
                    private readonly \Closure $next,
                ) {}

                public function currentRedactor(): PayloadRedactor
                {
                    return ($this->next)();
                }

                public function redact(array $payload): array
                {
                    return $payload;
                }
            };
        };

        return $makeFirst();
    }

    /**
     * @return object{value: int}
     */
    private function bindCountingStringRedactor(): object
    {
        $counter = new class
        {
            public int $value = 0;
        };

        $this->app->bind(PayloadRedactor::class, static function () use ($counter): PayloadRedactor {
            $counter->value++;

            return new class($counter->value) implements PayloadRedactor
            {
                public function __construct(
                    private readonly int $instance,
                ) {}

                public function redact(array $payload): array
                {
                    foreach ($payload as $key => $value) {
                        $payload[$key] = $this->redactValue($value);
                    }

                    return $payload;
                }

                private function redactValue(mixed $value): mixed
                {
                    if (is_array($value)) {
                        foreach ($value as $key => $nested) {
                            $value[$key] = $this->redactValue($nested);
                        }

                        return $value;
                    }

                    return is_string($value) ? 'redactor-'.$this->instance : $value;
                }
            };
        });

        return $counter;
    }
}
