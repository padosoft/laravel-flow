<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A constraint applied to every query the dashboard read model issues.
 *
 * This package has no notion of tenancy: none of its migrations define a
 * tenant column, so it cannot filter on one. But a host that has added its
 * own boundary — a `tenant_id` column across the flow tables, say — needs the
 * dashboard to honour it, and `FlowDashboardReadModel` is `final` precisely so
 * its DTO contract stays stable. Subclassing is therefore not the seam, and
 * decorating the class would mean reimplementing every read.
 *
 * So the host supplies the predicate and the read model applies it. The
 * package stays agnostic about what the restriction means; the host stays
 * authoritative about its own schema, which matters because a host that has
 * NOT added the column must not be handed a query referencing it.
 *
 * The scope is applied to all five base queries — runs, run nodes, audit,
 * approvals and webhook outbox — rather than only to the run list. Scoping
 * the list alone would still let a caller read a single foreign run's detail
 * by id, and the detail is where the payloads are.
 *
 * Implementations MUST be side-effect free and MUST NOT widen the query: they
 * receive a builder and return it with restrictions added.
 *
 * Implementations MUST resolve their subject inside `apply()`, when it runs —
 * never capture a resolved subject when the scope is constructed. The read
 * model is bound as a singleton, so under a long-lived container (Octane,
 * Swoole, a queue worker) a captured subject outlives the request that
 * resolved it and would be served to the next one. That failure is precisely
 * the leak this contract exists to prevent, and it is silent.
 *
 * Returning the builder unmodified means "no restriction". That is correct
 * for a deployment with no boundary to enforce, and it is why an
 * implementation that CANNOT resolve its subject must add an always-false
 * constraint instead of returning early: an unmodified builder reads as "no
 * restriction", so a failed lookup would otherwise widen into an
 * unrestricted read. Fail closed, in the implementation, where the meaning
 * is known.
 *
 * @api
 */
interface DashboardReadScope
{
    /**
     * Return the query with this scope's restrictions applied.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder;
}
