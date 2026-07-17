<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

/**
 * NOTE on the lost-CAS-race branch in {@see FlowEngine::cancel()}: when the run
 * transitions to a terminal state concurrently between the initial `find()` and
 * the compare-and-set, `updateWhereStatus` returns null and cancel() re-reads
 * and returns the ACTUAL winning terminal state. That branch is not directly
 * unit-tested here (these tests run single-threaded against SQLite, with no
 * injection point inside `persistAtomically`); its observable contract —
 * "return the current terminal state, never force `aborted`" — is the same one
 * `test_cancel_is_idempotent_for_an_already_terminal_run` pins for the
 * already-terminal case. Likewise, cancel() RETRIES its abort CAS against a
 * benign non-terminal flip (running <-> paused) so it never silently no-ops on
 * one — that retry path is also not directly injectable single-threaded; the
 * "abort a paused (non-terminal) run" leg it depends on is pinned by
 * {@see self::test_cancel_aborts_a_paused_run_and_terminates_its_active_nodes()}.
 */
final class CancelRunTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_cancel_aborts_a_paused_run_and_terminates_its_active_nodes(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.cancel.paused')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $paused = $engine->execute('flow.cancel.paused', []);
        $this->assertSame(FlowRun::STATUS_PAUSED, $paused->status);

        // Seed a node genuinely stuck in `running` (with a real start time) to
        // exercise the running → failed branch AND the duration_ms recording;
        // a synchronous run never leaves a node `running`, so seed it directly.
        FlowRunNodeRecord::query()->create([
            'run_id' => $paused->id,
            'node_id' => 'stuck',
            'node_type' => 'legacy.step',
            'status' => 'running',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        $cancelled = $engine->cancel($paused->id);

        // The returned run and the persisted row are both `aborted`.
        $this->assertSame(FlowRun::STATUS_ABORTED, $cancelled->status);
        $this->assertSame('aborted', FlowRunRecord::query()->findOrFail($paused->id)->status);

        $rows = FlowRunNodeRecord::query()->where('run_id', $paused->id)->get()->keyBy('node_id');
        $nodes = $rows->map(fn ($row) => $row->status);

        // The already-completed step is untouched; the paused approval node and
        // the running node are both terminated (paused/running → failed).
        $this->assertSame('succeeded', $nodes['create']);
        $this->assertSame('failed', $nodes['manager']);
        $this->assertSame('failed', $nodes['stuck']);
        // The running node's elapsed time is recorded on cancellation.
        $this->assertNotNull($rows['stuck']->duration_ms);
        // A cancelled node is stamped with a distinguishing reason so it reads
        // back as an explained cancellation, not an anonymous handler failure.
        $this->assertSame('FlowRunCancelled', $rows['stuck']->error_class);
        $this->assertSame('Run was cancelled.', $rows['stuck']->error_message);
        $this->assertSame('FlowRunCancelled', $rows['manager']->error_class);

        // The downstream `publish` step was never reached in this synchronous
        // run, so it has NO node row — cancel only terminates PERSISTED nodes
        // (documented sync-vs-queued behavior on cancel()).
        $this->assertArrayNotHasKey('publish', $nodes->all());
    }

    public function test_cancel_is_idempotent_for_an_already_terminal_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.cancel.completed')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.cancel.completed', []);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);

        // Cancelling a terminal run returns its CURRENT state, unchanged.
        $cancelled = $engine->cancel($run->id);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $cancelled->status);
        $this->assertSame('succeeded', FlowRunRecord::query()->findOrFail($run->id)->status);
    }

    public function test_terminate_is_a_compare_and_set_that_never_clobbers_a_moved_node(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        // A real run to satisfy the flow_run_nodes → flow_runs foreign key.
        $engine->define('flow.cas')->step('s', AlwaysSucceedsHandler::class)->register();
        $run = $engine->execute('flow.cas', []);

        $repo = $this->app->make(RunNodeRepository::class);

        FlowRunNodeRecord::query()->create([
            'run_id' => $run->id,
            'node_id' => 'n',
            'node_type' => 'legacy.step',
            'status' => 'running',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        // The first CAS (expected = running) wins.
        $this->assertTrue($repo->terminate($run->id, 'n', 'running', 'failed', now(), 1000));
        $this->assertSame('failed', FlowRunNodeRecord::query()->where('run_id', $run->id)->where('node_id', 'n')->value('status'));

        // A second attempt against the now-stale expected status is a no-op —
        // a node that has moved on is never clobbered (the cancel() race guard).
        $this->assertFalse($repo->terminate($run->id, 'n', 'running', 'skipped', now(), null));
        $this->assertSame('failed', FlowRunNodeRecord::query()->where('run_id', $run->id)->where('node_id', 'n')->value('status'));
    }

    public function test_cancel_throws_for_an_unknown_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->expectException(FlowExecutionException::class);
        $engine->cancel('does-not-exist');
    }

    public function test_cancel_requires_persistence_enabled(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $this->app->forgetInstance(FlowEngine::class);
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);
        $engine->cancel('any-run');
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
