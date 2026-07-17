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

### Additive `@api` (non-breaking)

- Per-node retry (`@api`): `#[Retry(int $tries = 1, int|array $backoff = 0, int $timeout = 0)]` (on a handler class or its `execute()` method) plus `Padosoft\LaravelFlow\Executor\RetryPolicy`. `NodeDefinition` gains a trailing `?RetryPolicy $retry = null` (additive — existing construction is unaffected). A graph node's `config['retry']` overrides the attribute per placement. `tries` is total attempts and clamps to a minimum of 1 (a deliberate divergence from Laravel job-level "0 = unlimited"). Exhausting a real retry budget (`tries > 1`) drives the node to `dead_letter`; a single failed attempt stays `failed`. `timeout` is a post-hoc wall-clock check in the synchronous runner (preemptive enforcement is a queue-worker concern).
- `#[Input]` and `PortDefinition` gain a `bool $multiple = false` flag (trailing, defaulted — existing call sites are unaffected). A `multiple` (fan-in) port coalesces every incoming wire into an ordered `list<mixed>` instead of rejecting the second wire; it is only valid on `PortType::Json` / `PortType::Any` ports whose handler property is `array`. `PortDefinition::toArray()` now includes a `multiple` key (additive; node-catalog schema version unchanged). `GraphValidator` permits N sources into a `multiple` target port (the single-source anti-fan-in rule is unchanged for normal ports). New executor helpers `Padosoft\LaravelFlow\Executor\InputRouter` / `RoutedInputs` (`@api`) resolve and validate a node's inputs from upstream outputs + config.
- Sub-flow / fan-out control nodes (`@api`): built-in `flow.subflow` (`SubFlowNode`), `flow.foreach` (`ForEachNode`), `flow.map` (`MapNode`). `flow` (+ optional `version`) selects a PUBLISHED child flow; `SubFlowNode` runs it once with `input`, `flow.foreach`/`flow.map` run it once per `items` entry (an array item is the child's input map, a scalar becomes `['value' => item]`), capped by `maxConcurrency`, emitting `results` — the ordered per-child output list. On the SYNCHRONOUS executor children run inline (`maxConcurrency` = sequential batch size); on the QUEUED executor each child is spawned as its own run and the parent node is suspended, resumed exactly once when the last child terminates (`maxConcurrency` = REAL concurrency). Both paths write a `flow_node_children` ledger row per child (for audit/Dashboard). `Contracts\NodeChildRepository` + `Executor\JoinCoordinator` + `Executor\ChildFlowRunner` are `@internal`. Migration: `2026_07_09_000010_create_flow_node_children_table` (publish + migrate). Also adds a nullable `flow_runs.graph` json column (migration `2026_07_09_000011`) storing a queued run's canonical graph so the fan-out join can reload a suspended parent and re-advance it — stored UNREDACTED (flow structure, not runtime data, same as `flow_definitions.graph`); as an incidental benefit this gives ad-hoc (non-published) queued graph runs a future path to replay.
- Queued graph execution (`@api`): `Flow::dispatchGraph(GraphDefinition $graph, array $input, ?FlowExecutionOptions $options = null, string $definitionName = 'graph'): string` runs a graph on the queue and returns the new run id (requires persistence enabled). A coordinator advances readiness inside a `flow_runs` row lock and fans independent ready nodes out to per-node jobs; each node executes through the SAME `NodeExecutor` seam as the synchronous `Flow::runGraph`, so the two paths cannot diverge. `Contracts\RunNodeRepository` gains three methods — `states(string $runId): array<string, NodeState>`, `claim(string $runId, string $nodeId, \DateTimeInterface $startedAt): bool` (an atomic compare-and-set `pending -> running`), and `releaseClaim(string $runId, string $nodeId): bool` (the inverse CAS `running -> pending`, used to recover a claim whose node job could not be enqueued). Custom `RunNodeRepository` implementers must add all three. The coordinator/job classes (`Executor\QueueGraphCoordinator`, `Executor\Jobs\CoordinatorJob`, `Executor\Jobs\NodeJob`) are `@internal`; route through the facade. Idempotency is DB-derived (the terminal node row is the completion marker) and each node is serialized by a per-node cache lock; queued node handlers must be idempotent (at-least-once, like v1 queued flows). Config: `laravel-flow.executor.queue` / `executor.lock_store` / `executor.lock_seconds` tune the queued path. NOTE: because each queued node runs in its own job, a downstream node reads its upstream outputs back from persistence — under enabled redaction those are the stored (redacted) values, whereas the synchronous runner passes unredacted in-memory outputs; redaction is off by default, so the two paths are byte-identical.
- Content-hash node cache (`@api`): `#[Cacheable(?int $ttl = null)]` (on a handler class or its `execute()` method) marks a node whose output may be served from a persistent cache. On a hit for the same `(nodeType, resolvedInputs, nodeConfig)` content hash the handler is SKIPPED and the cached outputs are returned (the node run records the hash in `flow_run_nodes.cache_hit`). `NodeDefinition` gains a trailing `?Cacheable $cacheable = null` (additive) and advertises it in `toArray()`. New `@api` seam `Padosoft\LaravelFlow\Executor\NodeCache` (+ `NodeCacheHit`) and the `Padosoft\LaravelFlow\Contracts\NodeCacheRepository` contract; `Executor\ContentHasher` and `Persistence\EloquentNodeCacheRepository` are `@internal`. Caching is inert on a dry run and when persistence is disabled. Redaction: a cache WRITE passes through the SAME `PayloadRedactor` as every other persisted payload (no carve-out) and is SKIPPED ENTIRELY whenever redaction would alter the output — so a cache hit can never return a value that diverges from what a fresh run would produce, and a node whose output contains a redacted-list key simply never caches. The content hash sorts associative keys recursively but PRESERVES list order (a node's fan-in inputs are ordered lists, so `[a,b]` and `[b,a]` hash differently). Migration: `2026_07_09_000012_create_flow_node_cache_table` (publish + migrate).
- Graph saga compensation (`@api`): when a graph run fails, ONLY completed (`succeeded`) nodes roll back — never failed, blocked, skipped, or paused nodes. The default `reverse-order` strategy guarantees reverse-topological execution order; the opt-in `parallel` strategy batches compensators concurrently and trades that ordering guarantee for throughput (candidates are batched in reverse-topological order but completion order is nondeterministic — same contract as v1 parallel compensation). Three compensation sources compose: a handler implementing the new `Padosoft\LaravelFlow\Node\CompensatableNode` interface (`compensate(NodeContext $context): void`; the context's `inputs` carry the node's recorded OUTPUT port map — what it produced and must undo); a compiled v1 step (`legacy.step`) whose `config['compensator']` names a v1 `FlowCompensator` — invoked with the original run input and the step's rebuilt `FlowStepResult`, with `FlowContext::$stepOutputs` empty, matching how `LegacyStepNodeAdapter` executes adapted steps in a graph (upstream data travels through input ports, never the v1 `stepOutputs` map); and the graph-level aggregate compensator from `GraphDefinition::$metadata['aggregate_compensator']`, which ALWAYS runs last and receives every succeeded node's outputs — this closes v1's reserved `withAggregateCompensator` for graph runs. New `@api` seam `Padosoft\LaravelFlow\Executor\GraphSaga` (+ `GraphSagaReport`); triggered automatically by both the synchronous `GraphRunner` and the queued coordinator's finalize (outside the run row lock). Reuses v1's `compensation_strategy` config key: `reverse-order` (default) or `parallel`, which batches per-node compensators through the Laravel Concurrency `compensation_parallel_driver` (the caller opts in, asserting compensator independence — same contract as v1). A throwing compensator is recorded and never aborts the remaining rollback; the run flips to `compensated` (with `compensation_status = 'succeeded'`) ONLY when every intended compensator succeeded, otherwise it keeps its failure state with `compensation_status = 'failed'`. Compensation never runs on a dry run. GOTCHA (queued path only): compensators read node outputs back from persistence, so under ENABLED redaction they see the stored (redacted) values — same structural caveat as queued inter-node routing; redaction is off by default.
- DAG dry-run plan + cost estimate (`@api`): `Padosoft\LaravelFlow\Executor\DryRun\DryRunPlanner::plan(GraphDefinition $graph, array $input = []): array{plan: ExecutionPlan, cost: CostEstimate}` statically computes the execution plan — Kahn waves (`ExecutionPlan::$waves`: wave 0 = roots, wave N = nodes whose predecessors all appear earlier, i.e. what COULD run concurrently) — plus a cost estimate summed from the new `#[Cost(estimate: ['tokens' => 1200, 'cents' => 3])]` attribute (on a handler class or its `execute()`, like `#[Retry]`/`#[Cacheable]`; free-form numeric dimensions, reflected onto `NodeDefinition` as a trailing `?Cost $cost = null` and advertised in `toArray()['cost']`). The planner executes NO handler and writes ZERO rows across every persistence table, by construction. The plan is OPTIMISTIC: whether a node self-skips is only knowable at run time (each handler decides its own dry-run behavior), so every node lands in a wave and `ExecutionPlan::$skipped` stays empty (the field exists for a future planner that can prove a branch dead). A node whose handler cannot be resolved still plans into its wave and simply advertises no cost — the planner is advisory and never aborts over a hint.
- Approval gate node on graphs (`@api`): the new built-in `flow.approval` node (`Padosoft\LaravelFlow\Executor\Nodes\ApprovalGateNode`) pauses a graph run exactly like v1's `approvalGate()` step — `NodeExecutor` (not the node itself) detects the pause and issues a one-time `ApprovalTokenManager` token (hash-only storage, same as v1). `Flow::resume($token, $payload)` / `Flow::reject($token, $payload)` now detect a target run's `engine === 'graph'` column and dispatch to an engine-agnostic path instead of v1's step-replay machinery — the SAME run-id-keyed cache lock and token-consume step are reused unchanged, so duplicate resume/reject calls stay idempotent exactly like v1. On approval, the paused node is marked `succeeded` with the decision payload as its single `out` port output and the queued coordinator (`QueueGraphCoordinator`/`CoordinatorJob`) re-advances the graph — reusing 100% of existing readiness/claim/finalize machinery, so a further downstream approval gate, fan-out, or failure all behave exactly as they would in a normal queued run. On reject, the node is marked `failed`, which (via the SAME finalize pass) automatically triggers `GraphSaga` compensation of completed upstream nodes — no new compensation code, this composes with C-PR8 unchanged. `Padosoft\LaravelFlow\Executor\GraphRunResult` gains a trailing `array $approvalTokens = []` (additive, keyed by the paused node's id) mirroring v1's `FlowRun::$approvalTokens` — the only place a plain (unhashed) token is available, from a synchronous `GraphRunner::run()` call. `Padosoft\LaravelFlow\Executor\GraphApprovalCoordinator` is `@internal`. GOTCHA: a synchronously-started run only persists a node row once it's reached, so resuming it hands off to the queued coordinator's pre-seeded-`pending`-row assumption for the first time — the resume path seeds any missing rows before dispatching, or downstream nodes would be silently skipped (see `docs/LESSON.md`, 2026-07-12).
- Batched step counts on the dashboard read model (`@api`): `FlowDashboardReadModel::stepCounts(array $runIds): array<string, int>` returns each run's step count in ONE grouped query, for a consumer (e.g. a runs list view) that needs a step count per row without paying `findRun()`'s full detail cost (steps + audit + approvals + webhook outbox) once per row. A run id absent from the result has zero steps — the caller defaults missing keys to 0. Purely additive; every existing `FlowDashboardReadModel` method signature is still unchanged.
- `Dashboard\StepSummary` gains a trailing `bool $cacheHit = false` read property (additive — defaulted, so existing positional construction is unaffected). It is `true` when the step's result was served from the node cache (`#[Cacheable]`/`NodeCache`), derived from whether the `flow_run_nodes.cache_hit` column is non-null. A cache hit is metadata ON an otherwise-`succeeded` step, not a distinct lifecycle status, so consumers render it as a badge/overlay on a succeeded node rather than as a new state; the boolean exposes only the hit/miss fact, never the stored content hash.
- Run cancellation (`@api`): `FlowEngine::cancel(string $runId, array $actor = []): FlowRun` (+ `Flow::cancel`) atomically transitions a non-terminal run to `aborted` and moves every still-active node to a transition-legal terminal state (a `pending` node → `skipped`, a `running`/`paused` node → `failed`; the `NodeState` enum surface is unchanged — no new "cancelled" case). It is idempotent: cancelling an already-terminal run — or losing the run-status compare-and-set to a concurrent completion — returns the run's CURRENT `FlowRun` state unchanged rather than forcing it. Requires persistence enabled (throws `FlowExecutionException` otherwise). `$actor` is reserved for future audit attribution (no actor-scoped row is written today). Coverage/limitations: (a) only PERSISTED node rows are terminated — a queued graph run pre-seeds a `pending` row per node (all flip to `skipped`), but a synchronously-executed run that paused mid-flight has no rows for not-yet-reached downstream nodes, so those are left un-rowed; (b) an in-flight queued node job may still write its own row afterward, but the run stays terminal; (c) cancel does NOT recompute the `flow_runs` node-count columns nor emit a broadcast settle-point snapshot — a subscribed dashboard learns of the abort on its next poll.
- Dashboard webhook redelivery (`@api`): `FlowEngine::redeliverWebhook(int $outboxId): bool` (+ `Flow::redeliverWebhook`) requeues ONE failed webhook-outbox row for delivery — it resets the row to `pending` with `attempts` cleared (so the existing `flow:deliver-webhooks` claim path, gated on `attempts < max_attempts`, re-attempts it) via a compare-and-set on `status = 'failed'`. Returns `true` only when a row with that id existed AND was in the `failed` state; an unknown id, an in-flight `delivering` lease, an already-`delivered` row, or an already-`pending` row all return `false` (nothing to redeliver, no state disturbed). The `int` id is surfaced by `FlowDashboardReadModel::failedWebhookOutbox()`/`listWebhookOutbox()` for a companion dashboard's "redeliver" action. The underlying `Persistence\EloquentWebhookOutboxRepository` stays `@internal` (it gains a public `redeliver(int): bool`, but hosts route through the `Flow`/`FlowEngine` `@api` method, not the repository).
- Opt-in graph run/node broadcasting (`@api`): new `laravel-flow.broadcasting.enabled` config (default `false`) + `broadcasting.channel_prefix` (default `laravel-flow`). When enabled, graph node transitions broadcast `Padosoft\LaravelFlow\Broadcasting\NodeTransitioned` and each run's settle point broadcasts `Padosoft\LaravelFlow\Broadcasting\GraphRunProgressUpdated` (aggregate snapshot) on a PRIVATE per-run channel `"{channel_prefix}.run.{runId}"` — both `ShouldBroadcastNow`, both `@api` with a pinned `broadcastWith()` payload shape (see `tests/Contract/ExecutorApiContractTest.php`). `Padosoft\LaravelFlow\Broadcasting\GraphProgressBroadcaster` (`@internal`) is the single dispatch point both the synchronous `GraphRunner` and the queued `QueueGraphCoordinator` call into. The package emits only — it ships NO channel authorization callback; the host application's `routes/channels.php` decides who may subscribe, and no specific broadcast driver (Reverb/Pusher/etc.) is required by the package (`illuminate/broadcasting` added to `composer.json` `require` for the contracts/channel classes only). Disabled by default: zero broadcast dispatches, no coupling to a configured broadcast connection (enforced by `tests/Architecture/BroadcastingIsolationTest.php`, which forbids any `Illuminate\Broadcasting`/`Illuminate\Contracts\Broadcasting` reference outside `src/Broadcasting/`). Decoupled from `persistence.enabled` — node/run broadcasts fire even with persistence off; a dry run always stays silent (zero externally-observable side effects). `NodeExecutor`, `GraphRunner`, and `QueueGraphCoordinator` each gain a trailing nullable `?GraphProgressBroadcaster $progressBroadcaster = null` constructor param (additive). CONSUMER WARNING: dispatch is wrapped in a broad catch (logged as a warning, never rethrown) so a broadcast-driver failure can never abort node/run execution — but that catch also swallows an exception thrown by ANY listener YOU attach to either event; do not attach a listener whose failure must be surfaced or must abort the run.

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
