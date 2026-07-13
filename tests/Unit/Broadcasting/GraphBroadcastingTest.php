<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelFlow\Broadcasting\GraphProgressBroadcaster;
use Padosoft\LaravelFlow\Broadcasting\GraphRunProgressUpdated;
use Padosoft\LaravelFlow\Broadcasting\NodeTransitioned;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CompensatableRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use RuntimeException;

final class GraphBroadcastingTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [
            QueueProbeNode::class,
            FailingGraphNode::class,
            CompensatableRecordingNode::class,
        ]);
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
    }

    private function linearGraph(): GraphDefinition
    {
        return new GraphDefinition(
            [new GraphNode('a', 'test.probe'), new GraphNode('b', 'test.probe')],
            [new Connection('a', 'out', 'b', 'in')],
        );
    }

    public function test_broadcasting_disabled_by_default_dispatches_nothing(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', false);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);

        Event::assertNotDispatched(NodeTransitioned::class);
        Event::assertNotDispatched(GraphRunProgressUpdated::class);
    }

    public function test_broadcasting_disabled_dispatches_nothing_on_the_queued_path_either(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', false);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $this->app->make(FlowEngine::class)->dispatchGraph($this->linearGraph(), []);

        Event::assertNotDispatched(NodeTransitioned::class);
        Event::assertNotDispatched(GraphRunProgressUpdated::class);
    }

    public function test_broadcasting_enabled_still_dispatches_nothing_on_a_dry_run(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $this->app->make(FlowEngine::class)->dryRunGraph($this->linearGraph(), []);

        Event::assertNotDispatched(NodeTransitioned::class);
        Event::assertNotDispatched(GraphRunProgressUpdated::class);
    }

    public function test_a_blocked_node_broadcasts_nothing_on_a_dry_run(): void
    {
        // persistBlocked() has its OWN broadcast call site (it never reaches
        // NodeExecutor::persist()) and must honor the same dry-run silence —
        // a dry run over a graph with an upstream failure still computes
        // Blocked in-memory (the state machine doesn't care that $store is
        // null), so this needs its own dedicated regression test.
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $graph = new GraphDefinition(
            [new GraphNode('f', 'test.fail'), new GraphNode('downstream', 'test.probe')],
            [new Connection('f', 'out', 'downstream', 'in')],
        );

        $this->app->make(FlowEngine::class)->dryRunGraph($graph, []);

        Event::assertNotDispatched(NodeTransitioned::class);
        Event::assertNotDispatched(GraphRunProgressUpdated::class);
    }

    public function test_broadcasting_enabled_dispatches_documented_payload_shape(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $result = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);

        Event::assertDispatched(NodeTransitioned::class, function (NodeTransitioned $event) use ($result): bool {
            $payload = $event->broadcastWith();

            return $event->runId === $result->runId
                && $payload['node_id'] === 'a'
                && $payload['node_type'] === 'test.probe'
                && $payload['state'] === NodeState::Succeeded->value
                && $payload['sequence'] === 0
                && is_string($payload['occurred_at']);
        });

        Event::assertDispatched(GraphRunProgressUpdated::class, function (GraphRunProgressUpdated $event) use ($result): bool {
            $payload = $event->broadcastWith();

            return $payload['run_id'] === $result->runId
                && $payload['status'] === RunState::Succeeded->value
                && $payload['nodes_total'] === 2
                && $payload['nodes_completed'] === 2
                && $payload['nodes_failed'] === 0
                && $payload['progress_pct'] === 100.0
                && is_string($payload['occurred_at']);
        });
    }

    public function test_broadcasting_works_even_when_persistence_is_disabled(): void
    {
        // The key promised decoupling: broadcasting.enabled and
        // persistence.enabled are INDEPENDENT toggles. This must not merely be
        // asserted in a docblock — exercise it directly.
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $result = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);

        Event::assertDispatched(NodeTransitioned::class, fn (NodeTransitioned $event): bool => $event->runId === $result->runId && $event->nodeId === 'a');
        Event::assertDispatched(NodeTransitioned::class, fn (NodeTransitioned $event): bool => $event->runId === $result->runId && $event->nodeId === 'b');
        Event::assertDispatched(GraphRunProgressUpdated::class, fn (GraphRunProgressUpdated $event): bool => $event->runId === $result->runId);
    }

    public function test_blocked_node_broadcasts_only_after_its_row_is_durable(): void
    {
        // Real (non-faked) listener, same technique as the run-progress
        // ordering test: persistBlocked() has its OWN persist-then-broadcast
        // call site (not NodeExecutor's), so it needs its own regression proof
        // that a subscriber never observes `blocked` before the row exists.
        $seenStatus = null;
        Event::listen(NodeTransitioned::class, function (NodeTransitioned $event) use (&$seenStatus): void {
            if ($event->nodeId === 'downstream') {
                $seenStatus = DB::table('flow_run_nodes')
                    ->where('run_id', $event->runId)->where('node_id', 'downstream')
                    ->value('status');
            }
        });
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);

        $graph = new GraphDefinition(
            [new GraphNode('f', 'test.fail'), new GraphNode('downstream', 'test.probe')],
            [new Connection('f', 'out', 'downstream', 'in')],
        );

        $this->app->make(FlowEngine::class)->runGraph($graph, []);

        $this->assertSame(NodeState::Blocked->value, $seenStatus, 'the row was durable before the broadcast fired');
    }

    public function test_blocked_nodes_broadcast_a_transition_too(): void
    {
        // A blocked node is marked directly by GraphRunner::persistBlocked()/the
        // queued coordinator's poison-propagation loop — NEITHER goes through
        // NodeExecutor::persist(), the seam every OTHER node-transition
        // broadcast relies on. Without its own wiring, a live monitor would see
        // the aggregate snapshot count a blocked node as failed with no
        // per-node event explaining why.
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class]);

        $graph = new GraphDefinition(
            [new GraphNode('f', 'test.fail'), new GraphNode('downstream', 'test.probe')],
            [new Connection('f', 'out', 'downstream', 'in')],
        );

        $result = $this->app->make(FlowEngine::class)->runGraph($graph, []);

        Event::assertDispatched(NodeTransitioned::class, fn (NodeTransitioned $event): bool => $event->runId === $result->runId
            && $event->nodeId === 'downstream'
            && $event->state === NodeState::Blocked);
    }

    public function test_blocked_nodes_broadcast_a_transition_on_the_queued_path_too(): void
    {
        // QueueGraphCoordinator's poison-propagation loop is a SEPARATE call
        // site from GraphRunner::persistBlocked() — collected under the row
        // lock but broadcast after it commits. Only the sync path was covered
        // above; a regression here (missing event, wrong node id, wrong
        // ordering) needs its own dedicated proof.
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class]);

        $graph = new GraphDefinition(
            [new GraphNode('f', 'test.fail'), new GraphNode('downstream', 'test.probe')],
            [new Connection('f', 'out', 'downstream', 'in')],
        );

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);

        Event::assertDispatched(NodeTransitioned::class, fn (NodeTransitioned $event): bool => $event->runId === $runId
            && $event->nodeId === 'downstream'
            && $event->state === NodeState::Blocked);
    }

    public function test_a_throwing_broadcast_driver_never_prevents_node_persistence(): void
    {
        // GraphProgressBroadcaster must swallow every Throwable internally: an
        // uncaught exception here would abort NodeExecutor::persist() BEFORE
        // the durable DB write, and on the queued path the job would then be
        // retried — re-executing a handler that already succeeded.
        $throwingDispatcher = new class implements Dispatcher
        {
            public function listen($events, $listener = null) {}

            public function hasListeners($eventName)
            {
                return false;
            }

            public function subscribe($subscriber) {}

            public function until($event, $payload = []) {}

            public function dispatch($event, $payload = [], $halt = false)
            {
                throw new RuntimeException('broadcast driver is down');
            }

            public function push($event, $payload = []) {}

            public function flush($event) {}

            public function forget($event) {}

            public function forgetPushed() {}
        };

        // Scope the throwing dispatcher to GraphProgressBroadcaster ONLY — never
        // rebind the app-wide Dispatcher::class, or every other event dispatch
        // in the graph execution path (audit events, etc.) would also break,
        // making this test brittle to unrelated changes.
        $this->app->instance(GraphProgressBroadcaster::class, new GraphProgressBroadcaster(
            $throwingDispatcher,
            true,
            'laravel-flow',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable,
        ));
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);

        $result = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(['out' => ['id' => 'a']], $result->nodeOutputs['a']);
        $this->assertSame(['out' => ['id' => 'b']], $result->nodeOutputs['b']);
        $this->assertSame(1, QueueProbeNode::count('a'));
        $this->assertSame(1, QueueProbeNode::count('b'));
    }

    public function test_run_progress_broadcasts_only_after_the_run_row_is_durable(): void
    {
        // Real (non-faked) listener: proves the DB write actually happened
        // BEFORE this event fires, on both engines — Event::assertDispatched
        // alone can't distinguish "before" from "after" a DB write, so this
        // inspects the row from inside a live listener instead of faking.
        $seenFinishedAt = [];
        Event::listen(GraphRunProgressUpdated::class, function (GraphRunProgressUpdated $event) use (&$seenFinishedAt): void {
            $seenFinishedAt[$event->runId] = DB::table('flow_runs')->where('id', $event->runId)->value('finished_at');
        });
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);

        $syncResult = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);
        $queuedRunId = $this->app->make(FlowEngine::class)->dispatchGraph($this->linearGraph(), []);

        $this->assertNotNull($seenFinishedAt[$syncResult->runId] ?? null, 'sync run row was durable before the broadcast fired');
        $this->assertNotNull($seenFinishedAt[$queuedRunId] ?? null, 'queued run row was durable before the broadcast fired');
    }

    public function test_approval_gate_pause_broadcasts_only_after_the_token_is_issued(): void
    {
        // Real (non-faked) listener, same technique as the run-durability test
        // above: a subscriber reacting to NodeTransitioned(paused) may
        // immediately query for the pending approval — the token must already
        // exist by the time this event is observable, not issued afterward.
        $sawApprovalRow = null;
        Event::listen(NodeTransitioned::class, function (NodeTransitioned $event) use (&$sawApprovalRow): void {
            if ($event->state !== NodeState::Paused) {
                return;
            }

            $sawApprovalRow = DB::table('flow_approvals')->where('run_id', $event->runId)->exists();
        });
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);

        $graph = new GraphDefinition([new GraphNode('g', 'flow.approval')], []);
        $this->app->make(FlowEngine::class)->runGraph($graph, []);

        $this->assertTrue($sawApprovalRow, 'the approval token was already issued by the time the paused transition broadcast');
    }

    public function test_run_progress_broadcasts_the_compensated_outcome_after_a_full_rollback(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([GraphRunProgressUpdated::class]);

        $graph = new GraphDefinition(
            [new GraphNode('a', 'test.saga.comp'), new GraphNode('f', 'test.fail')],
            [new Connection('a', 'out', 'f', 'in')],
        );

        $result = $this->app->make(FlowEngine::class)->runGraph($graph, []);

        $this->assertSame(RunState::Compensated, $result->state);

        $statuses = [];
        Event::assertDispatched(GraphRunProgressUpdated::class, function (GraphRunProgressUpdated $event) use ($result, &$statuses): bool {
            if ($event->runId !== $result->runId) {
                return false;
            }

            $statuses[] = $event->status;

            return true;
        });

        $this->assertSame([RunState::PartiallySucceeded, RunState::Compensated], $statuses, 'a second, accurate snapshot follows the pre-compensation one');
    }

    public function test_queued_run_progress_broadcasts_the_compensated_outcome_after_a_full_rollback(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([GraphRunProgressUpdated::class]);

        $graph = new GraphDefinition(
            [new GraphNode('a', 'test.saga.comp'), new GraphNode('f', 'test.fail')],
            [new Connection('a', 'out', 'f', 'in')],
        );

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);

        $this->assertSame('compensated', DB::table('flow_runs')->where('id', $runId)->value('status'));

        $statuses = [];
        Event::assertDispatched(GraphRunProgressUpdated::class, function (GraphRunProgressUpdated $event) use ($runId, &$statuses): bool {
            if ($event->runId !== $runId) {
                return false;
            }

            $statuses[] = $event->status;

            return true;
        });

        $this->assertSame([RunState::PartiallySucceeded, RunState::Compensated], $statuses, 'a second, accurate snapshot follows the pre-compensation one on the queued path too');
    }

    public function test_broadcasting_channel_is_private_and_run_scoped(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class]);

        $first = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);
        $second = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);

        $this->assertNotSame($first->runId, $second->runId);

        $channels = [];

        Event::assertDispatched(NodeTransitioned::class, function (NodeTransitioned $event) use (&$channels): bool {
            $channel = $event->broadcastOn();
            $this->assertInstanceOf(PrivateChannel::class, $channel);
            $channels[] = $channel->name;

            return true;
        });

        $this->assertContains('private-laravel-flow.run.'.$first->runId, $channels);
        $this->assertContains('private-laravel-flow.run.'.$second->runId, $channels);
    }

    public function test_queued_and_sync_paths_broadcast_the_same_node_transition_sequence(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);

        // ONE Event::fake() call for the whole test: GraphProgressBroadcaster is
        // a container singleton, constructed (and its Dispatcher captured) on
        // first resolution — a SECOND Event::fake() call mid-test would swap
        // the container's bound fake to a new instance that the already-built
        // singleton never sees, silently losing every subsequent dispatch.
        // Distinguish the two runs by their own (distinct) run id instead.
        Event::fake([NodeTransitioned::class]);

        $syncResult = $this->app->make(FlowEngine::class)->runGraph($this->linearGraph(), []);
        $queuedRunId = $this->app->make(FlowEngine::class)->dispatchGraph($this->linearGraph(), []);

        // ORDER matters here (no sort()): 'a' must transition before 'b' — the
        // downstream node cannot become ready until its upstream succeeds — and
        // each event's own `sequence` field must match its topological position
        // (0 for the root, 1 for its dependent), not just the SET of (nodeId,
        // state) pairs that occurred.
        $expected = ['a:succeeded:0', 'b:succeeded:1'];
        $this->assertSame($expected, $this->transitionSequence($syncResult->runId));
        $this->assertSame($expected, $this->transitionSequence($queuedRunId));
    }

    /**
     * @return list<string>
     */
    private function transitionSequence(string $runId): array
    {
        /** @var list<NodeTransitioned> $dispatched */
        $dispatched = Event::dispatched(NodeTransitioned::class)
            ->pluck(0)
            ->filter(static fn (NodeTransitioned $event): bool => $event->runId === $runId)
            ->all();

        // Preserve DISPATCH order (no sorting) — this is what actually proves
        // the two engines emit transitions in the same order, not merely the
        // same unordered set.
        return array_map(
            static fn (NodeTransitioned $event): string => "{$event->nodeId}:{$event->state->value}:{$event->sequence}",
            array_values($dispatched),
        );
    }
}
