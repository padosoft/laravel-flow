<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Dashboard\Pagination;
use Padosoft\LaravelFlow\Dashboard\RunFilter;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingReadScope;

/**
 * Covers the host-supplied read scope on the dashboard read model.
 *
 * The package defines no tenant column, so these tests constrain on
 * `definition_name` instead: what is under test is that the restriction is
 * honoured everywhere a read can originate, not what the restriction means.
 * Deciding the predicate is the host's job.
 */
final class FlowDashboardReadScopeTest extends PersistenceTestCase
{
    private const VISIBLE = 'flow.scope.visible';

    private const HIDDEN = 'flow.scope.hidden';

    public function test_a_scope_constrains_the_run_list(): void
    {
        [$visible] = $this->seedOneRunPerDefinition();

        $page = $this->scopedReader()->listRuns(new RunFilter, new Pagination(1, 10));

        $this->assertSame(1, $page->total);
        $this->assertCount(1, $page->items);
        $this->assertSame($visible->id, $page->items[0]->id);
    }

    public function test_a_scope_constrains_run_detail_so_an_excluded_run_cannot_be_read_by_id(): void
    {
        [$visible, $hidden] = $this->seedOneRunPerDefinition();

        $reader = $this->scopedReader();

        // The load-bearing assertion. A scope applied only to the run LIST
        // would leave this returning the full detail — steps, audit and
        // payloads included — to a caller who merely knows the id.
        $this->assertNull($reader->findRun($hidden->id));
        $this->assertNotNull($reader->findRun($visible->id));
    }

    public function test_a_scope_constrains_kpi_aggregates(): void
    {
        $this->seedOneRunPerDefinition();

        $this->assertSame(2, $this->reader()->kpis()->totalRuns);
        $this->assertSame(1, $this->scopedReader()->kpis()->totalRuns);
    }

    public function test_a_scope_constrains_step_counts(): void
    {
        [$visible, $hidden] = $this->seedOneRunPerDefinition();

        $counts = $this->scopedReader()->stepCounts([$visible->id, $hidden->id]);

        $this->assertArrayHasKey($visible->id, $counts);
        $this->assertArrayNotHasKey($hidden->id, $counts);
    }

    public function test_a_scope_is_offered_every_base_query_not_only_the_run_list(): void
    {
        [$visible] = $this->seedOneRunPerDefinition();

        $scope = new RecordingReadScope;
        $this->reader()->withScope($scope)->findRun($visible->id);

        // Reading one run's detail touches all five: the run itself plus its
        // nodes, audit trail, approvals and webhook outbox. A host that adds
        // a boundary column to every flow table can therefore enforce it on
        // every table, not just the one the list happens to read.
        $this->assertSame([
            'flow_approvals',
            'flow_audit',
            'flow_run_nodes',
            'flow_runs',
            'flow_webhook_outbox',
        ], $this->sortedUnique($scope->tables));
    }

    public function test_a_scope_filters_the_detail_sub_queries_and_not_only_the_run(): void
    {
        [$visible] = $this->seedOneRunPerDefinition();

        $excludeEverything = static fn (Builder $q): Builder => $q->whereRaw('1 = 0');

        $detail = $this->reader()->withScope(new RecordingReadScope([
            'flow_audit' => $excludeEverything,
            'flow_approvals' => $excludeEverything,
            'flow_webhook_outbox' => $excludeEverything,
        ]))->findRun($visible->id);

        // The run itself is unrestricted here, so it still resolves. What the
        // three sub-queries return is what is under test: "the scope was
        // offered this table" is weaker than "the scope changed the result",
        // and only the second means a host boundary is actually enforced.
        $this->assertNotNull($detail);
        $this->assertSame([], $detail->audit);
        $this->assertSame([], $detail->approvals);
        $this->assertSame([], $detail->webhookOutbox);

        $unscoped = $this->reader()->findRun($visible->id);
        $this->assertNotNull($unscoped);
        $this->assertNotSame([], $unscoped->audit);
    }

    public function test_without_a_scope_every_read_is_unconstrained(): void
    {
        $this->seedOneRunPerDefinition();

        $reader = $this->reader();

        $this->assertSame(2, $reader->listRuns(new RunFilter, new Pagination(1, 10))->total);
        $this->assertSame(2, $reader->kpis()->totalRuns);
    }

    public function test_with_scope_returns_a_copy_and_leaves_the_original_unconstrained(): void
    {
        $this->seedOneRunPerDefinition();

        $reader = $this->reader();
        $scoped = $reader->withScope($this->visibleOnlyScope());

        $this->assertNotSame($reader, $scoped);
        $this->assertSame(2, $reader->listRuns(new RunFilter, new Pagination(1, 10))->total);
        $this->assertSame(1, $scoped->listRuns(new RunFilter, new Pagination(1, 10))->total);
    }

    public function test_passing_null_clears_a_previously_applied_scope(): void
    {
        $this->seedOneRunPerDefinition();

        $cleared = $this->scopedReader()->withScope(null);

        $this->assertSame(2, $cleared->listRuns(new RunFilter, new Pagination(1, 10))->total);
    }

    /**
     * @return array{0: FlowRun, 1: FlowRun}
     */
    private function seedOneRunPerDefinition(): array
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        foreach ([self::VISIBLE, self::HIDDEN] as $name) {
            $engine->define($name)->step('one', AlwaysSucceedsHandler::class)->register();
        }

        return [
            $engine->execute(self::VISIBLE, []),
            $engine->execute(self::HIDDEN, []),
        ];
    }

    private function visibleOnlyScope(): RecordingReadScope
    {
        return new RecordingReadScope([
            'flow_runs' => static fn (Builder $q): Builder => $q->where('definition_name', self::VISIBLE),
            'flow_run_nodes' => static fn (Builder $q): Builder => $q->whereIn(
                'run_id',
                fn ($sub) => $sub->select('id')->from('flow_runs')->where('definition_name', self::VISIBLE),
            ),
        ]);
    }

    private function scopedReader(): FlowDashboardReadModel
    {
        return $this->reader()->withScope($this->visibleOnlyScope());
    }

    private function reader(): FlowDashboardReadModel
    {
        return $this->app->make(FlowDashboardReadModel::class);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sortedUnique(array $tables): array
    {
        $unique = array_values(array_unique($tables));
        sort($unique);

        return $unique;
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);

        return $this->app->make(FlowEngine::class);
    }
}
