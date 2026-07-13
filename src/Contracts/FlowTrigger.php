<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;

/**
 * A source that starts a flow run in response to something external — a
 * cron tick, a host application event, an inbound HTTP request. Concrete
 * trigger implementations (`ScheduleTrigger`, `EventTrigger`,
 * `WebhookTrigger`) live in the satellite `padosoft/laravel-flow-connect`
 * package and each own their OWN registration and input-mapping logic;
 * `fire()` is the single shared seam that hands the already-mapped input
 * to this engine. The contract lives in CORE (not in the satellite
 * package) so it is a stable, SemVer-covered surface any trigger source —
 * first-party or third-party — can depend on and implement against.
 *
 * `fire()` mirrors {@see FlowEngine::dispatch()}'s own fire-and-forget
 * contract exactly: it queues a run and returns nothing. `dispatch()`
 * itself returns `mixed` — its underlying job is queue- and
 * after-commit-deferred, so what that return value actually resolves to
 * depends on the configured queue connection/driver and is NOT something
 * a caller should rely on as a run identifier. `fire()` deliberately
 * does not surface it. A trigger that needs the resulting run id must
 * read it back later (e.g. via `FlowDashboardReadModel`), not from
 * `fire()`.
 *
 * @api
 */
interface FlowTrigger
{
    /**
     * @param  array<string, mixed>  $input  the trigger's own mapped input, already shaped for the target flow's declared input
     */
    public function fire(string $flowName, array $input = [], ?FlowExecutionOptions $options = null): void;
}
