# Upgrade Guide

This document describes how to upgrade `padosoft/laravel-flow` between minor and major versions. From v1.0 onward, the package follows [Semantic Versioning](https://semver.org/) for the surface marked `@api` in source. Classes marked `@internal` are not covered by SemVer; they may change between minors.

## Public-vs-internal contract

- **`@api`** — Stable for v1.x. Breaking changes only happen on a major bump and ship with this guide updated. The `@api` annotation appears in the class docblock of:
  - Facade: `Padosoft\LaravelFlow\Facades\Flow`.
  - Engine and DTOs: `FlowEngine`, `FlowDefinitionBuilder`, `FlowExecutionOptions`, `FlowDefinition`, `FlowStep`, `FlowRun`, `FlowStepResult`, `FlowContext`, `IssuedApprovalToken`, `ApprovalGate`, `ApprovalTokenManager`, `WebhookDeliveryClient`, `WebhookDeliveryResult`.
  - Public extension contracts: everything in `Padosoft\LaravelFlow\Contracts\*`.
  - Events: everything in `Padosoft\LaravelFlow\Events\*`.
  - Exceptions: everything in `Padosoft\LaravelFlow\Exceptions\*`.
  - Step / compensator hooks: `FlowStepHandler`, `FlowCompensator`.
  - Dashboard contracts: everything in `Padosoft\LaravelFlow\Dashboard\*` plus the `Authorization\*` sub-namespace.
  - Node contract (v2 graph-engine preview, added by Macro A of the Flow 2.0 program): everything in `Padosoft\LaravelFlow\Node\*` including `Attributes\*` and `Exceptions\*` — pinned by `tests/Contract/NodeApiContractTest`. Stability note: this surface ships ahead of its first consumer (the graph engine, Macros B/C) and is covered by SemVer from the first tagged release that includes it; until v2.0.0 ships, additive evolution may occur (new `PortType` cases are documented as open-for-extension, and `NodeContext` may gain trailing defaulted constructor parameters). The legacy-definition registration wiring for `LegacyStepNodeAdapter::definitionFor()` intentionally arrives with the graph executor (Macro C).
  - Graph/Definitions contract (v2 graph-engine preview, added by Macro B of the Flow 2.0 program): everything in `Padosoft\LaravelFlow\Graph\*` including `Exceptions\*` — `GraphNode`, `Connection`, `GraphDefinition`, `GraphValidator`, `GraphSerializer`, `StoredDefinition`, `DefinitionSigner`, `GraphTransfer`, `Flow2Importer`, and the `InvalidGraphException` / `DefinitionNotFoundException` / `DefinitionLifecycleException` / `DefinitionSignatureException` exceptions — plus `Padosoft\LaravelFlow\Contracts\DefinitionRepository`, pinned together by `tests/Contract/GraphApiContractTest`. Stability note: same pre-v2 posture as the Node contract above — this surface ships ahead of the graph executor (Macro C) and is covered by SemVer from the first tagged release that includes it, but until v2.0.0 additive evolution may occur. Legacy-node execution resolution and version-exact replay re-execution intentionally arrive with Macro C; `flow:export` / `flow:import` / `flow:replay` stay `@internal` Artisan commands (invoke by signature only).
- **`@internal`** — Implementation detail. Host applications must not extend, type-hint, or reflect on these classes:
  - `Padosoft\LaravelFlow\Persistence\*` (Eloquent stores, redactor scopes, pruner, repositories).
  - `Padosoft\LaravelFlow\Models\*` (Eloquent records).
  - `Padosoft\LaravelFlow\Queue\*` (queue retry policy).
  - `Padosoft\LaravelFlow\Jobs\*` (`RunFlowJob`).
  - `Padosoft\LaravelFlow\Console\*` (Artisan commands; invoke them by signature only).

If you currently depend on internal classes, switch to the matching public contract before upgrading. Open an issue if the surface is missing for your use case rather than reaching into internals.

---

## v1.x → v2.0 (in progress — Flow 2.0 program)

The v2.0 major unifies step and node persistence into a single table written by both the v1 linear engine and the new graph executor. **The v1 authoring and execution API is unchanged** — the fluent builder (`Flow::define()->step()->…->register()`), the engine's execution methods (`execute` / `dryRun` / `dispatch` / `resume` / `reject`), and v1 execution semantics (step ordering, compensation order, approval resume) are observably identical. The persistence *extension contracts* used by custom-store implementers (`FlowStore`, the step repository) **do** change — see the breaking changes below; applications that only use the fluent builder and facade are unaffected, while anyone who implemented a custom `FlowStore` must migrate.

### Breaking changes (internal persistence surface)

- **`flow_steps` is retired and replaced by `flow_run_nodes`.** A v1 step is now stored as a node row (`node_id` = the step name, `node_type = 'legacy.step'`, `sequence` preserved). The new table is a superset of `flow_steps` with graph/retry/cache columns (`attempts`, `cache_hit`, `available_at`, `node_type`) and renames the JSON payload columns `input`/`output` to `inputs`/`outputs`.
- **`Contracts\StepRunRepository` (`@api`) is superseded by `Contracts\RunNodeRepository` (`@api`).** The method shape is unchanged (`createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord` and `forRun(string $runId): Collection`); the second argument and JSON attribute keys use node vocabulary (`node_id`, `inputs`, `outputs`) and every node row requires a `node_type`.
- **`FlowStore::steps()` (`@api`) is renamed to `FlowStore::runNodes(): RunNodeRepository`.** Custom `FlowStore` implementers must rename the accessor and back it with a `RunNodeRepository`.
- **`Models\FlowStepRecord` (`@internal`) is removed**, replaced by `Models\FlowRunNodeRecord`. `Persistence\EloquentStepRunRepository` is removed, replaced by `Persistence\EloquentRunNodeRepository`.
- The `flow_audit.step_name` column is **kept** (its value equals the node id for a v1 step); it is not renamed, so audit read/write code is unaffected.
- `Dashboard\StepSummary` and every `FlowDashboardReadModel` method signature are **unchanged** — a "step" projection is a run-node projection, so the dashboard public contract is preserved (pinned by the golden projection test in `FlowDashboardReadModelTest`).

### Required migration

Publish and run the new migrations. `2026_07_09_000009_migrate_flow_steps_to_run_nodes` copies any existing `flow_steps` rows into `flow_run_nodes` and then drops `flow_steps`. It is guarded (`hasTable`) so it is a safe no-op on installations that never published `flow_steps`, and idempotent once the legacy table is gone.

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

Custom `FlowStore` / step-repository implementers must migrate their own storage to the node-run shape and expose `runNodes(): RunNodeRepository` before upgrading.

---

## v0.3 → v1.0

### Highlights

- v1.0 ships **package-side dashboard contracts** under `Padosoft\LaravelFlow\Dashboard\*`. The companion app (`padosoft/padosoft-laravel-flow-dashboard`) is a separate repo. See [`DASHBOARD_APP_SPEC.md`](DASHBOARD_APP_SPEC.md).
- All public classes are now annotated with `@api`. All implementation detail classes are annotated with `@internal`.
- New Composer-script gate: `composer test` plus the existing `composer format:test`, `composer analyse`, and `composer validate --strict --no-check-publish` are the canonical CI gates.

### Breaking changes

- `DashboardActionAuthorizer` default binding is **`DenyAllAuthorizer`** — the dashboard rejects every action until a host application explicitly binds its own implementation. If you were experimenting with a permissive default, opt in to `AllowAllAuthorizer` for development:

  ```php
  use Padosoft\LaravelFlow\Dashboard\Authorization\AllowAllAuthorizer;
  use Padosoft\LaravelFlow\Dashboard\Authorization\DashboardActionAuthorizer;

  $this->app->bind(DashboardActionAuthorizer::class, AllowAllAuthorizer::class);
  ```

  Production deployments MUST bind a host implementation that enforces real RBAC.

- Internal namespaces (`Persistence`, `Models`, `Queue`, `Jobs`, `Console`) are now annotated `@internal`. Direct use is unsupported and may break in any minor release. Switch to the matching `Contracts\*` interface or the public service (`FlowDashboardReadModel` for read access; `Flow::execute()`, `Flow::dispatch()`, `Flow::resume()`, `Flow::reject()` for state changes).

### Required migrations

No new schema migrations are required for v1.0. The dashboard read model queries the existing tables published in v0.2 / v0.3 (`flow_runs`, `flow_steps`, `flow_audit`, `flow_approvals`, `flow_webhook_outbox`).

If you upgraded from v0.2 directly without applying v0.3, run:

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

### Configuration changes

No new config keys for v1.0. Existing `webhook.*`, `approval.*`, `queue.*`, and `persistence.*` keys are unchanged.

---

## v0.2 → v0.3

### Highlights

- Approval gates: `approvalGate($name)` step type, persisted hashed tokens, `Flow::resume()` / `Flow::reject()`.
- CLI commands: `flow:approve`, `flow:reject`, `flow:deliver-webhooks`.
- Signed HMAC webhook outbox delivery for `flow.completed`, `flow.failed`, `flow.paused`, `flow.resumed`.

### Required migrations

Two new migrations ship alongside v0.3. Publish and run:

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

The migrations create `flow_approvals` and `flow_webhook_outbox` tables and add the `previous_token_hash` column to `flow_approvals` for downstream-gate token reissue.

### Configuration additions

```php
// config/laravel-flow.php
'approval' => [
    'token_ttl_minutes' => env('LARAVEL_FLOW_APPROVAL_TOKEN_TTL_MINUTES', 1440),
],
'webhook' => [
    'enabled' => env('LARAVEL_FLOW_WEBHOOK_ENABLED', false),
    'url' => env('LARAVEL_FLOW_WEBHOOK_URL', ''),
    'secret' => env('LARAVEL_FLOW_WEBHOOK_SECRET', null),
    'retry_base_delay_seconds' => 30,
    'max_attempts' => 3,
    'timeout_seconds' => 5,
],
```

### Behavior notes

- `Flow::resume()` and `Flow::reject()` require a shared cache lock store. Bind `queue.lock_store` to `redis` / `memcached` / `database` / `dynamodb`. The process-local `array` store is rejected.
- Plain approval tokens are returned only at issuance time on `$run->approvalTokens[<step>]->plainTextToken`. Persisted records keep only SHA-256 hashes.
- The companion app spec at `docs/DASHBOARD_APP_SPEC.md` describes how to consume v0.3 from the dashboard.

---

## v0.1 → v0.2

### Highlights

- Opt-in DB persistence: `flow_runs`, `flow_steps`, `flow_audit`.
- Queued dispatch: `Flow::dispatch()`, `RunFlowJob`.
- Terminal-run replay: `flow:replay`.
- Parallel compensation strategy.
- Idempotency keys, correlation IDs, retention pruning (`flow:prune`).

### Required migrations

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

### Configuration additions

```php
'persistence' => [
    'enabled' => env('LARAVEL_FLOW_PERSISTENCE_ENABLED', false),
    'redaction' => [
        'enabled' => env('LARAVEL_FLOW_REDACTION_ENABLED', true),
        'replacement' => '[redacted]',
        'keys' => ['api_key', 'authorization', 'password', 'secret', 'token'],
    ],
    'retention' => [
        'days' => env('LARAVEL_FLOW_RETENTION_DAYS', null),
    ],
],
'queue' => [
    'lock_store' => env('LARAVEL_FLOW_QUEUE_LOCK_STORE', null),
    'lock_seconds' => 3600,
    'lock_retry_seconds' => 30,
    'tries' => null,
    'backoff_seconds' => null,
],
'compensation_strategy' => env('LARAVEL_FLOW_COMPENSATION', 'reverse-order'),
'compensation_parallel_driver' => env('LARAVEL_FLOW_COMPENSATION_PARALLEL_DRIVER', 'process'),
```

### Behavior notes

- v0.1 `Flow::execute()` returned an in-memory `FlowRun`; v0.2 still does, and additionally persists run/step/audit rows when `persistence.enabled=true` and the execution is not a dry-run.
- Audit events (`FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated`) continue to dispatch through Laravel's event dispatcher. Persisted audit rows additionally require `audit_trail_enabled=true` (default) and a non-dry-run execution.
- Listener failures during `FlowStep*` events propagate after best-effort recovery; `FlowCompensated` listener failures are swallowed so rollback is never interrupted.
- Replay copies the persisted input verbatim. Values redacted before storage stay redacted on replay; if a flow needs secrets after replay, fetch them from a separate secret store rather than from `flow_runs.input`.

---

## When breaking changes apply

We follow SemVer for the `@api` surface from v1.0:

- **Major version bump** (e.g. v1.x → v2.0) — Removing or changing the signature of an `@api` method, removing an `@api` class, narrowing return types in a non-covariant way, or renaming an event class.
- **Minor version bump** (e.g. v1.0 → v1.1) — Adding new `@api` methods, classes, events, config keys, or contracts. Existing `@api` surface keeps its v1.0 behavior.
- **Patch version bump** (e.g. v1.0.0 → v1.0.1) — Bug fixes that preserve the `@api` surface.

Internal namespaces are excluded; expect them to change with any release.

## See also

- [`docs/MIGRATION_DURABLE.md`](MIGRATION_DURABLE.md) — moving from `bytestgang/laravel-durable-workflows`.
- [`docs/MIGRATION_SYMFONY.md`](MIGRATION_SYMFONY.md) — moving from `symfony/workflow`.
- [`docs/DASHBOARD_APP_SPEC.md`](DASHBOARD_APP_SPEC.md) — companion dashboard app brief.
- [`CHANGELOG.md`](../CHANGELOG.md) — release-by-release diff (added in v1.0).
