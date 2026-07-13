<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelFlow\Broadcasting\GraphRunProgressUpdated;
use Padosoft\LaravelFlow\Broadcasting\NodeTransitioned;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class GraphBroadcastingTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [QueueProbeNode::class]);
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

    public function test_broadcasting_disabled_dispatches_nothing_on_a_dry_run(): void
    {
        $this->app['config']->set('laravel-flow.broadcasting.enabled', true);
        Event::fake([NodeTransitioned::class, GraphRunProgressUpdated::class]);

        $this->app->make(FlowEngine::class)->dryRunGraph($this->linearGraph(), []);

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

        $this->assertSame(['a:succeeded', 'b:succeeded'], $this->transitionSequence($syncResult->runId));
        $this->assertSame(['a:succeeded', 'b:succeeded'], $this->transitionSequence($queuedRunId));
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

        $sequence = array_map(
            static fn (NodeTransitioned $event): string => "{$event->nodeId}:{$event->state->value}",
            $dispatched,
        );

        sort($sequence);

        return $sequence;
    }
}
