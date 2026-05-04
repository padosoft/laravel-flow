<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Queue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Jobs\RunFlowJob;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

final class DatabaseQueueDispatchTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'after_commit' => false,
            'connection' => 'testing',
            'driver' => 'database',
            'queue' => 'default',
            'retry_after' => 90,
            'table' => 'jobs',
        ]);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.queue.lock_store', 'file');
        $app['config']->set('laravel-flow.queue.tries', 1);
        $app['config']->set('laravel-flow.queue.backoff_seconds', '5,10');
    }

    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;

        $this->migrateFlowTables();

        Schema::dropIfExists('jobs');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');

        parent::tearDown();
    }

    public function test_database_queue_serializes_retry_policy_and_worker_executes_run_flow_job(): void
    {
        Flow::define('flow.database-queue')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        Flow::dispatch(
            'flow.database-queue',
            ['tenant' => 'acme'],
            FlowExecutionOptions::make(idempotencyKey: 'idem-database-queue'),
        );

        $this->assertSame(1, DB::table('jobs')->count());

        $payload = DB::table('jobs')->value('payload');
        $this->assertIsString($payload);

        $decodedPayload = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decodedPayload);
        $this->assertSame(1, $decodedPayload['maxTries']);
        $this->assertSame('5,10', $decodedPayload['backoff']);
        $this->assertStringContainsString('RunFlowJob', (string) $decodedPayload['displayName']);

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => 'default',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('flow_runs')->where('idempotency_key', 'idem-database-queue')->count());
    }

    public function test_database_queue_serializes_zero_tries_without_collapsing_to_worker_defaults(): void
    {
        $this->app->make('queue')->connection('database')->push(new RunFlowJob(
            'flow.database-queue-zero',
            dispatchId: 'database-queue-zero-dispatch',
            lockStore: 'file',
            tries: 0,
        ));

        $payload = DB::table('jobs')->value('payload');
        $this->assertIsString($payload);

        $decodedPayload = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decodedPayload);
        $this->assertSame(0, $decodedPayload['maxTries']);
    }
}
