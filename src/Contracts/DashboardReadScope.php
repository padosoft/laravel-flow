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
 * receive a builder and return it with restrictions added. Returning it
 * unmodified means "no restriction", which is the correct behaviour for a
 * deployment with no boundary to enforce — and the reason a host that means
 * to restrict must never let an unresolvable subject fall through to that
 * branch. Fail closed there, in the host, where the meaning is known.
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
