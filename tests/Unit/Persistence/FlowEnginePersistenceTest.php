<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Events\FlowPaused;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ClockAdvancingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ClockAdvancingThrowingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\CustomSecretFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\EmptyOutputHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecretFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThrowingCompensator;
use RuntimeException;

final class FlowEnginePersistenceTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
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
        $this->assertIsInt($steps[0]->sequence);
        $this->assertIsInt($steps[0]->duration_ms);
        $this->assertSame('[redacted]', $steps[0]->input['flow_input']['token']);
        $this->assertSame(['create'], $steps[1]->input['step_output_keys']);
        $this->assertArrayNotHasKey('step_outputs', $steps[1]->input);
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

    public function test_execution_options_persist_correlation_and_idempotency_keys(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.identity')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute(
            'flow.persist.identity',
            ['safe' => 'visible'],
            FlowExecutionOptions::make(correlationId: 'corr-123', idempotencyKey: 'identity-123'),
        );

        $runRecord = FlowRunRecord::query()->find($run->id);

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame('corr-123', $run->correlationId);
        $this->assertSame('identity-123', $run->idempotencyKey);
        $this->assertSame('corr-123', $runRecord->correlation_id);
        $this->assertSame('identity-123', $runRecord->idempotency_key);
    }

    public function test_idempotency_key_returns_existing_persisted_run_without_reexecuting_steps(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.idempotent')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $options = FlowExecutionOptions::make(correlationId: 'corr-original', idempotencyKey: 'identity-once');

        $firstRun = $engine->execute('flow.persist.idempotent', ['attempt' => 1], $options);
        $secondRun = $engine->execute('flow.persist.idempotent', ['attempt' => 2], $options);

        $this->assertSame($firstRun->id, $secondRun->id);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $secondRun->status);
        $this->assertSame('corr-original', $secondRun->correlationId);
        $this->assertSame('identity-once', $secondRun->idempotencyKey);
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
        $this->assertArrayHasKey('create', $secondRun->stepResults);
        $this->assertTrue($secondRun->stepResults['create']->success);
        $this->assertSame($firstRun->stepResults['create']->output, $secondRun->stepResults['create']->output);
        $this->assertSame(1, (int) FlowRunRecord::query()->where('idempotency_key', 'identity-once')->count());
        $this->assertSame(1, (int) FlowStepRecord::query()->where('run_id', $firstRun->id)->count());
    }

    public function test_idempotency_create_race_returns_existing_run_before_side_effects(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        /** @var FlowStore $inner */
        $inner = $this->app->make(FlowStore::class);
        $state = new IdempotencyCreateRaceState;
        $store = new class($inner, $state) implements FlowStore
        {
            public function __construct(
                private readonly FlowStore $inner,
                private readonly IdempotencyCreateRaceState $state,
            ) {}

            public function runs(): RunRepository
            {
                return new class($this->inner->runs(), $this->state) implements RunRepository
                {
                    public function __construct(
                        private readonly RunRepository $inner,
                        private readonly IdempotencyCreateRaceState $state,
                    ) {}

                    public function create(array $attributes): FlowRunRecord
                    {
                        if (($attributes['idempotency_key'] ?? null) === 'identity-race') {
                            $winnerAttributes = $attributes;
                            $winnerAttributes['id'] = 'existing-race-run';

                            $this->inner->create($winnerAttributes);
                            $this->state->winnerInserted = true;

                            throw new RuntimeException('duplicate idempotency race');
                        }

                        return $this->inner->create($attributes);
                    }

                    public function update(string $runId, array $attributes): FlowRunRecord
                    {
                        return $this->inner->update($runId, $attributes);
                    }

                    public function find(string $runId): ?FlowRunRecord
                    {
                        return $this->inner->find($runId);
                    }

                    public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord
                    {
                        if ($idempotencyKey === 'identity-race' && $this->state->winnerInserted === false) {
                            return null;
                        }

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
                return $callback();
            }
        };

        /** @var array<string, mixed> $config */
        $config = $this->app['config']->get('laravel-flow');
        $engine = new FlowEngine($this->app, $this->app['events'], $config, $store);

        $engine->define('flow.persist.idempotent-race')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute(
            'flow.persist.idempotent-race',
            ['attempt' => 1],
            FlowExecutionOptions::make(idempotencyKey: 'identity-race'),
        );

        $this->assertSame('existing-race-run', $run->id);
        $this->assertSame(FlowRun::STATUS_RUNNING, $run->status);
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
        $this->assertSame(1, (int) FlowRunRecord::query()->where('idempotency_key', 'identity-race')->count());
        $this->assertSame(0, (int) FlowStepRecord::query()->where('run_id', 'existing-race-run')->count());
    }

    public function test_idempotency_key_cannot_reuse_a_different_flow_definition(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();
        $options = FlowExecutionOptions::make(idempotencyKey: 'identity-shared');

        $engine->define('flow.persist.idempotent.first')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();
        $engine->define('flow.persist.idempotent.second')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.persist.idempotent.first', [], $options);

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('The supplied idempotency key is already associated with a different flow definition.');

        $engine->execute('flow.persist.idempotent.second', [], $options);
    }

    public function test_successful_empty_step_output_is_preserved_in_persisted_run_output(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.empty-output')
            ->step('noop', EmptyOutputHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.empty-output', []);
        $runRecord = FlowRunRecord::query()->find($run->id);

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertArrayHasKey('noop', $runRecord->output);
        $this->assertSame([], $runRecord->output['noop']);
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
        $this->assertArrayHasKey('create', $runRecord->output);
        $this->assertArrayNotHasKey('charge', $runRecord->output);

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

    public function test_approval_gate_persists_paused_run_and_step_state(): void
    {
        $this->migrateFlowTables();
        Event::fake([FlowPaused::class]);
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.approval-paused')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', DryRunAwareHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.approval-paused', []);

        $this->assertArrayHasKey('manager', $run->approvalTokens);
        $issuedToken = $run->approvalTokens['manager'];

        $runRecord = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_PAUSED, $runRecord->status);
        $this->assertNull($runRecord->finished_at);
        $this->assertNull($runRecord->duration_ms);
        $this->assertArrayHasKey('create', $runRecord->output);
        $this->assertArrayNotHasKey('manager', $runRecord->output);

        $approvalStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'manager')
            ->first();

        $this->assertInstanceOf(FlowStepRecord::class, $approvalStep);
        $this->assertSame('paused', $approvalStep->status);
        $this->assertSame(true, $approvalStep->output['approval_required']);
        $this->assertSame($issuedToken->approvalId, $approvalStep->output['approval_id']);
        $this->assertSame($issuedToken->expiresAt->format(DateTimeInterface::ATOM), $approvalStep->output['approval_expires_at']);
        $this->assertArrayNotHasKey('token', $approvalStep->output);

        $approvalRecord = FlowApprovalRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'manager')
            ->first();

        $this->assertInstanceOf(FlowApprovalRecord::class, $approvalRecord);
        $this->assertSame($issuedToken->approvalId, $approvalRecord->id);
        $this->assertSame(FlowApprovalRecord::STATUS_PENDING, $approvalRecord->status);
        $this->assertSame(ApprovalTokenManager::hashToken($issuedToken->plainTextToken), $approvalRecord->token_hash);
        $this->assertNotSame($issuedToken->plainTextToken, $approvalRecord->token_hash);

        Event::assertDispatched(FlowPaused::class, fn (FlowPaused $event): bool => $event->result->output['approval_id'] === $issuedToken->approvalId
            && ! array_key_exists('token', $event->result->output));

        $this->assertSame([
            'FlowStepStarted',
            'FlowStepCompleted',
            'FlowStepStarted',
            'FlowPaused',
        ], FlowAuditRecord::query()->where('run_id', $run->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_parallel_compensation_strategy_persists_each_successful_compensation(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.compensation_strategy', 'parallel');
        $this->app['config']->set('laravel-flow.compensation_parallel_driver', 'sync');
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.parallel-compensation')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('reserve', SecondHandler::class)
            ->compensateWith(SecondStepCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.parallel-compensation', []);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertTrue($runRecord->compensated);
        $this->assertSame('succeeded', $runRecord->compensation_status);
        $this->assertSame(2, FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowCompensated')
            ->count());
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
        $this->assertStringNotContainsString('json-dash-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('json-camel-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('auth-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('abc123', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('[redacted] [redacted]', (string) $failedStep->error_message);
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

                return is_string($value)
                    ? str_replace('custom-secret', '[custom-redacted]', $value)
                    : $value;
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

    public function test_resolved_engine_uses_current_payload_redactor_binding_at_execution_time(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->app->bind(PayloadRedactor::class, static fn (): PayloadRedactor => new class implements PayloadRedactor
        {
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

                return is_string($value)
                    ? str_replace('custom-secret', '[late-redacted]', $value)
                    : $value;
            }
        });

        $engine->define('flow.persist.late-redactor')
            ->step('charge', CustomSecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.late-redactor', ['token' => 'custom-secret']);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();
        $failedAudit = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowStepFailed')
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertSame('[late-redacted]', $runRecord->input['token']);
        $this->assertStringContainsString('[late-redacted]', (string) $failedStep->error_message);
        $this->assertSame($failedStep->error_message, $failedAudit->payload['error_message']);
    }

    public function test_engine_freezes_payload_redactor_for_text_and_json_persistence_during_execution(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        $this->app->make(FlowStore::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
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

                    return is_string($value)
                        ? str_replace('custom-secret', '[scoped-redacted-'.$this->instance.']', $value)
                        : $value;
                }
            };
        });

        $engine->define('flow.persist.scoped-redactor')
            ->step('charge', CustomSecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.scoped-redactor', ['token' => 'custom-secret']);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();
        $failedAudit = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowStepFailed')
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertSame(1, $counter->value);
        $this->assertSame('[scoped-redacted-1]', $runRecord->input['token']);
        $this->assertStringContainsString('[scoped-redacted-1]', (string) $failedStep->error_message);
        $this->assertSame($failedStep->error_message, $failedAudit->payload['error_message']);
    }

    public function test_engine_freezes_payload_redactor_for_redactor_aware_flow_store_decorators(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        $counter = new class
        {
            public int $value = 0;
        };
        $tracker = new class
        {
            public int $withPayloadRedactorCalls = 0;
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
                        if (is_string($value)) {
                            $payload[$key] = str_replace('custom-secret', '[aware-redacted-'.$this->instance.']', $value);
                        }
                    }

                    return $payload;
                }
            };
        });

        /** @var FlowStore $inner */
        $inner = $this->app->make(FlowStore::class);
        $store = new class($inner, $tracker) implements RedactorAwareFlowStore
        {
            public function __construct(
                private readonly FlowStore $inner,
                private readonly object $tracker,
            ) {}

            public function withPayloadRedactor(PayloadRedactor $redactor): FlowStore
            {
                $this->tracker->withPayloadRedactorCalls++;

                $inner = $this->inner instanceof RedactorAwareFlowStore
                    ? $this->inner->withPayloadRedactor($redactor)
                    : $this->inner;

                return new self($inner, $this->tracker);
            }

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
                return $this->inner->audit();
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };

        /** @var array<string, mixed> $config */
        $config = $this->app['config']->get('laravel-flow');
        $engine = new FlowEngine($this->app, $this->app['events'], $config, $store);

        $engine->define('flow.persist.redactor-aware-store')
            ->step('charge', CustomSecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.redactor-aware-store', ['token' => 'custom-secret']);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertSame(1, $counter->value);
        $this->assertSame(1, $tracker->withPayloadRedactorCalls);
        $this->assertSame('[aware-redacted-1]', $runRecord->input['token']);
        $this->assertStringContainsString('[aware-redacted-1]', (string) $failedStep->error_message);
    }

    public function test_engine_unwraps_current_payload_redactor_provider_for_text_and_json_redaction(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        $counter = new class
        {
            public int $value = 0;
        };

        $this->app->bind(PayloadRedactor::class, static function () use ($counter): PayloadRedactor {
            $counter->value++;
            $inner = new class($counter->value) implements PayloadRedactor
            {
                public function __construct(
                    private readonly int $instance,
                ) {}

                public function redact(array $payload): array
                {
                    foreach ($payload as $key => $value) {
                        if (is_string($value)) {
                            $payload[$key] = str_replace('custom-secret', '[provider-redacted-'.$this->instance.']', $value);
                        }
                    }

                    return $payload;
                }
            };

            return new class($inner) implements CurrentPayloadRedactorProvider
            {
                public function __construct(
                    private readonly PayloadRedactor $inner,
                ) {}

                public function currentRedactor(): PayloadRedactor
                {
                    return $this->inner;
                }

                public function redact(array $payload): array
                {
                    foreach ($payload as $key => $value) {
                        if (is_string($value)) {
                            $payload[$key] = str_replace('custom-secret', '[outer-redacted]', $value);
                        }
                    }

                    return $payload;
                }
            };
        });

        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.provider-redactor')
            ->step('charge', CustomSecretFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.provider-redactor', ['token' => 'custom-secret']);

        $runRecord = FlowRunRecord::query()->find($run->id);
        $failedStep = FlowStepRecord::query()
            ->where('run_id', $run->id)
            ->where('step_name', 'charge')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $failedStep);
        $this->assertSame(1, $counter->value);
        $this->assertSame('[provider-redacted-1]', $runRecord->input['token']);
        $this->assertStringContainsString('[provider-redacted-1]', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('[outer-redacted]', (string) $failedStep->error_message);
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
        $this->assertStringNotContainsString('json-dash-secret', (string) $failedStep->error_message);
        $this->assertStringNotContainsString('json-camel-secret', (string) $failedStep->error_message);
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

    public function test_in_memory_execution_does_not_resolve_payload_redactor_when_persistence_is_disabled(): void
    {
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
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persist.disabled-redactor')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.persist.disabled-redactor', []);

        $this->assertSame(0, $counter->value);
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

    public function test_enabled_persistence_surfaces_broken_store_binding(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->bind(
            FlowStore::class,
            static fn (): FlowStore => throw new RuntimeException('store binding down'),
        );
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persist.store-binding-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.store-binding-down', []);
            $this->fail('The broken store binding should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('store binding down', $exception->getMessage());
        }
    }

    public function test_enabled_persistence_surfaces_broken_payload_redactor_binding(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->bind(
            PayloadRedactor::class,
            static fn (): PayloadRedactor => throw new RuntimeException('redactor binding down'),
        );
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persist.redactor-binding-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.redactor-binding-down', []);
            $this->fail('The broken redactor binding should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('redactor binding down', $exception->getMessage());
        }
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

    public function test_engine_uses_one_store_instance_inside_persistence_transactions(): void
    {
        $this->migrateFlowTables();

        /** @var FlowStore $inner */
        $inner = $this->app->make(FlowStore::class);
        $tracker = new \ArrayObject(['inside' => 0, 'outside' => 0]);

        $this->app->bind(
            FlowStore::class,
            static fn (): FlowStore => new class($inner, $tracker) implements FlowStore
            {
                private bool $inTransaction = false;

                public function __construct(
                    private readonly FlowStore $inner,
                    private readonly \ArrayObject $tracker,
                ) {}

                public function runs(): RunRepository
                {
                    $recordWrite = function (): void {
                        $this->recordWrite();
                    };

                    return new class($this->inner->runs(), $recordWrite) implements RunRepository
                    {
                        public function __construct(
                            private readonly RunRepository $inner,
                            private readonly \Closure $recordWrite,
                        ) {}

                        public function create(array $attributes): FlowRunRecord
                        {
                            $this->recordWrite();

                            return $this->inner->create($attributes);
                        }

                        public function update(string $runId, array $attributes): FlowRunRecord
                        {
                            $this->recordWrite();

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

                        private function recordWrite(): void
                        {
                            ($this->recordWrite)();
                        }
                    };
                }

                public function steps(): StepRunRepository
                {
                    $recordWrite = function (): void {
                        $this->recordWrite();
                    };

                    return new class($this->inner->steps(), $recordWrite) implements StepRunRepository
                    {
                        public function __construct(
                            private readonly StepRunRepository $inner,
                            private readonly \Closure $recordWrite,
                        ) {}

                        public function createOrUpdate(string $runId, string $stepName, array $attributes): FlowStepRecord
                        {
                            $this->recordWrite();

                            return $this->inner->createOrUpdate($runId, $stepName, $attributes);
                        }

                        public function forRun(string $runId): Collection
                        {
                            return $this->inner->forRun($runId);
                        }

                        private function recordWrite(): void
                        {
                            ($this->recordWrite)();
                        }
                    };
                }

                public function audit(): AuditRepository
                {
                    $recordWrite = function (): void {
                        $this->recordWrite();
                    };

                    return new class($this->inner->audit(), $recordWrite) implements AuditRepository
                    {
                        public function __construct(
                            private readonly AuditRepository $inner,
                            private readonly \Closure $recordWrite,
                        ) {}

                        public function append(
                            string $runId,
                            string $event,
                            array $payload = [],
                            ?string $stepName = null,
                            ?array $businessImpact = null,
                            ?DateTimeInterface $occurredAt = null,
                        ): FlowAuditRecord {
                            $this->recordWrite();

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

                        private function recordWrite(): void
                        {
                            ($this->recordWrite)();
                        }
                    };
                }

                public function transaction(callable $callback): mixed
                {
                    $this->inTransaction = true;

                    try {
                        return $this->inner->transaction($callback);
                    } finally {
                        $this->inTransaction = false;
                    }
                }

                private function recordWrite(): void
                {
                    if ($this->inTransaction) {
                        $this->tracker['inside']++;

                        return;
                    }

                    $this->tracker['outside']++;
                }
            },
        );
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.transaction-store-instance')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.persist.transaction-store-instance', []);

        $this->assertGreaterThan(0, $tracker['inside']);
        $this->assertSame(0, $tracker['outside']);
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
        $this->assertNull($runRecord->output);
        $this->assertSame('failed', $stepRecord->status);
        $this->assertSame(RuntimeException::class, $stepRecord->error_class);
        $this->assertStringNotContainsString('plain-secret', (string) $stepRecord->error_message);
        $this->assertSame([], $stepRecord->input['step_output_keys']);
        $this->assertArrayNotHasKey('step_outputs', $stepRecord->input);
        $this->assertSame('FlowStepCompleted', $failedAudit->payload['listener_event']);
        $this->assertStringNotContainsString('plain-secret', (string) $failedAudit->payload['error_message']);
        $this->assertCount(1, RecordingCompensator::$invocations);
        $this->assertSame(AlwaysSucceedsHandler::class, RecordingCompensator::$invocations[0]['originalOutput']['handler']);
    }

    public function test_runtime_abort_failed_step_business_impact_is_excluded_from_persisted_run_aggregate(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->app['events']->listen(
            FlowStepCompleted::class,
            static fn (): never => throw new RuntimeException('listener exploded after impact'),
        );

        $engine->define('flow.persist.completed-listener-impact')
            ->step('project', DryRunAwareHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->register();

        try {
            $engine->execute('flow.persist.completed-listener-impact', []);
            $this->fail('The throwing listener should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('listener exploded after impact', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()->first();
        $stepRecord = FlowStepRecord::query()
            ->where('step_name', 'project')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $stepRecord);
        $this->assertSame('failed', $stepRecord->status);
        $this->assertNull($runRecord->business_impact);
    }

    public function test_runtime_abort_step_fallback_still_retries_final_run_state(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingRunUpdateStatusOnAttempt(FlowRun::STATUS_COMPENSATED, 1);

        $this->app['events']->listen(
            FlowStepCompleted::class,
            static fn (): never => throw new RuntimeException('completed listener exploded'),
        );

        $engine->define('flow.persist.runtime-abort-run-retry')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->register();

        try {
            $engine->execute('flow.persist.runtime-abort-run-retry', []);
            $this->fail('The throwing listener should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('completed listener exploded', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()->first();
        $stepRecord = FlowStepRecord::query()
            ->where('step_name', 'create')
            ->first();
        $failedAudit = FlowAuditRecord::query()
            ->where('event', 'FlowStepFailed')
            ->where('step_name', 'create')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertInstanceOf(FlowStepRecord::class, $stepRecord);
        $this->assertInstanceOf(FlowAuditRecord::class, $failedAudit);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertTrue($runRecord->compensated);
        $this->assertSame('failed', $stepRecord->status);
        $this->assertSame('FlowStepCompleted', $failedAudit->payload['listener_event']);
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
        $compensatedAudits = FlowAuditRecord::query()
            ->where('run_id', $run->id)
            ->where('event', 'FlowCompensated')
            ->get();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertCount(2, RecordingCompensator::$invocations);
        $this->assertCount(2, $compensatedAudits);
        $this->assertFalse((bool) ($compensatedAudits[0]->payload['listener_failed'] ?? false));
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

    public function test_paused_transition_persistence_failure_records_failed_step_state(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithFailingAudit('FlowPaused');

        $engine->define('flow.persist.paused-audit-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->approvalGate('manager')
            ->register();

        try {
            $engine->execute('flow.persist.paused-audit-down', []);
            $this->fail('The failing paused audit repository should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit down for FlowPaused', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()
            ->where('definition_name', 'flow.persist.paused-audit-down')
            ->first();
        $approvalStep = FlowStepRecord::query()
            ->where('step_name', 'manager')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_COMPENSATED, $runRecord->status);
        $this->assertInstanceOf(FlowStepRecord::class, $approvalStep);
        $this->assertSame('failed', $approvalStep->status);
        $this->assertSame(RuntimeException::class, $approvalStep->error_class);
        $this->assertCount(1, RecordingCompensator::$invocations);
    }

    public function test_paused_listener_failure_expires_issued_approval_token(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->app['events']->listen(
            FlowPaused::class,
            static fn (): never => throw new RuntimeException('paused listener exploded'),
        );

        $engine->define('flow.persist.paused-listener-down')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(RecordingCompensator::class)
            ->approvalGate('manager')
            ->register();

        try {
            $engine->execute('flow.persist.paused-listener-down', []);
            $this->fail('The failing paused listener should abort execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('paused listener exploded', $exception->getMessage());
        }

        $approvalRecord = FlowApprovalRecord::query()
            ->where('step_name', 'manager')
            ->first();

        $this->assertInstanceOf(FlowApprovalRecord::class, $approvalRecord);
        $this->assertSame(FlowApprovalRecord::STATUS_EXPIRED, $approvalRecord->status);
        $this->assertNotNull($approvalRecord->decided_at);
        $this->assertNull($approvalRecord->consumed_at);
        $this->assertCount(1, RecordingCompensator::$invocations);
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
        Event::fake([FlowCompensated::class]);
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
        Event::assertNotDispatched(FlowCompensated::class);
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

        $runRecord = FlowRunRecord::query()->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_ABORTED, $runRecord->status);
        $this->assertTrue($runRecord->compensated);
        $this->assertNull($runRecord->failed_step);
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

        $runRecord = FlowRunRecord::query()->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_FAILED, $runRecord->status);
        $this->assertFalse($runRecord->compensated);
    }

    public function test_compensation_failure_persists_failed_state_without_marking_run_compensated(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.compensation-fails')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(ThrowingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        try {
            $engine->execute('flow.persist.compensation-fails', []);
            $this->fail('The throwing compensator should abort execution.');
        } catch (FlowCompensationException $exception) {
            $this->assertStringContainsString('compensation completed with 1 failed compensator', $exception->getMessage());
        }

        $runRecord = FlowRunRecord::query()->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_FAILED, $runRecord->status);
        $this->assertFalse($runRecord->compensated);
        $this->assertSame('failed', $runRecord->compensation_status);
    }

    public function test_compensation_failure_advances_finished_at_to_rollback_failure_time(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();
        $started = Carbon::parse('2026-05-02 10:00:00');
        $rollbackFailed = Carbon::parse('2026-05-02 10:00:05');

        $engine->define('flow.persist.compensation-fails-after-time')
            ->step('create', AlwaysSucceedsHandler::class)
            ->compensateWith(ClockAdvancingThrowingCompensator::class)
            ->step('charge', AlwaysFailsHandler::class)
            ->register();

        Date::setTestNow($started);

        try {
            $engine->execute('flow.persist.compensation-fails-after-time', []);
            $this->fail('The throwing compensator should abort execution.');
        } catch (FlowCompensationException $exception) {
            $this->assertStringContainsString('clock-advanced rollback failure', $exception->getMessage());
        } finally {
            Date::setTestNow();
        }

        $runRecord = FlowRunRecord::query()
            ->where('definition_name', 'flow.persist.compensation-fails-after-time')
            ->first();

        $this->assertInstanceOf(FlowRunRecord::class, $runRecord);
        $this->assertSame(FlowRun::STATUS_FAILED, $runRecord->status);
        $this->assertSame($rollbackFailed->getTimestamp(), $runRecord->finished_at->getTimestamp());
        $this->assertSame(5000, $runRecord->duration_ms);
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

final class IdempotencyCreateRaceState
{
    public bool $winnerInserted = false;
}
