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
 * package; each owns its own registration and input-mapping logic, and
 * `fire()` is the single shared seam that hands the already-mapped input
 * to this engine. The contract lives in CORE (not in the satellite
 * package) so it is a stable, SemVer-covered surface any trigger source —
 * first-party or third-party — can depend on and implement against.
 *
 * `fire()` is fire-and-forget: it queues a run and its OWN return type is
 * `void`, deliberately not surfacing {@see FlowEngine::dispatch()}'s
 * `mixed` return value — what that value actually resolves to depends on
 * the configured queue connection/driver and the surrounding transaction
 * state, and must never be relied on as a run identifier. A trigger that
 * needs the resulting run id must read it back later (e.g. via
 * `FlowDashboardReadModel`), not from `fire()`.
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
