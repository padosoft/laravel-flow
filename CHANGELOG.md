# Changelog

All notable changes to `padosoft/laravel-flow` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0 onward, SemVer applies to source classes annotated with `@api`. Classes annotated `@internal` are not covered by the SemVer guarantee; see [`docs/UPGRADE.md`](docs/UPGRADE.md) for the full policy.

## [Unreleased]

### Added

- **Per-port branching (`@api`)**: `NodeResult::branch(array $outputs, array $activePorts)` lets a node activate only some of its output ports. A wire from an inactive port is dead; a node whose every incoming wire is dead is **skipped** (recorded `skipped`, never run) and so is everything downstream that depended only on it, in the same readiness pass. A node with at least one live incoming wire still runs, with the dead input absent, so joins after a branch should use `flow.merge` or optional ports. This is what a condition / switch node needs; until now the executor could only decide readiness per node, so the branch not taken still ran and failed with `invalid_input`.
- **Readiness API**: `ReadinessResolver::resolve()` gains an optional third argument `$activePorts` (node id => activated ports) and `ReadinessDecision` a trailing defaulted `$skipped`. With no map, every decision is identical to 2.5.
- **`Contracts\BranchAwareRunNodeRepository` (`@api`, optional)**: `activePorts(string $runId): array`, implemented by the Eloquent repository. A custom `RunNodeRepository` that does not implement it is still read through `forRun()`.
- **Dashboard**: `StepSummary` gains a trailing defaulted `$activePorts` so a run view can show which branch was taken.
- **Forensics**: a forensic bundle records each branching / branch-skipped node's `active_ports` (present only for those nodes, so every non-branching bundle and its digest are unchanged), and `ForensicVerifier` now cross-checks the branch skips: a node that ran although every incoming wire was dead, or a recorded skip the decisions do not justify, is a `failed` routing finding.
- **Migration** `2026_09_28_000001_add_active_ports_to_flow_run_nodes.php`: a nullable `flow_run_nodes.active_ports` JSON column, written only by a branching node.
- **Timed resume (`@api`)**: `NodeResult::pausedUntil(DateTimeInterface $resumeAt, array $outputs = [])` pauses a node until a point in time and lets the engine resume it — the primitive a delay/timer node needs; until now a node that returned `paused()` could never be resumed by anything but an approval. On a **queued** run the node is stored `paused` with `resume_at` and a delayed job completes it (no worker sleeps; a wait longer than `executor.timer_max_job_delay_seconds`, default 900 = the SQS ceiling, hops across several jobs). On a **synchronous** run the executor sleeps inline up to `executor.max_inline_delay_seconds` (default 5) and otherwise fails the node with an actionable message. A dry run and a time already past complete immediately. Resuming never re-runs the handler, and it never issues an approval token.
- **`Contracts\TimerRepository` (`@api`, optional)**: `dueTimers()`, `pendingTimer()`, `resumeTimer()` (the compare-and-set that completes a due timer exactly once), `isResumedTimer()` (lets a retry recognise a completed timer). Implemented by the Eloquent repository.
- **`php artisan flow:resume-due-timers {--limit=500} {--sync}`**: the safety net for a lost job or a queue driver that cannot delay (`sync`); idempotent, so schedule it every minute.
- **Dashboard**: `StepSummary` gains a trailing defaulted `$resumeAt`.
- **Migration** `2026_09_28_000002_add_resume_at_to_flow_run_nodes.php`: a nullable `flow_run_nodes.resume_at` (`timestampTz`, indexed with `status`), written only by a timer and kept after it resumes. Deliberately not `available_at`, which records a retry backoff and is set on any node that retried before pausing.

### Notes

- Strictly opt-in. A node that merely omits an optional output is not branching and behaves exactly as in 2.5, and a non-branching node's persisted row is byte-identical to before.
- The migration is only required once a graph branches. Without it, ordinary graphs are unaffected and a branching graph fails with an explicit "run the v2.6 migrations" error.
- Activating an output port the node did not declare fails the node. Branch results are never node-cached.
- Timed resume is strictly opt-in as well: a graph with no `pausedUntil()` node behaves exactly as in 2.5, and a non-timer row is byte-identical. `NodeResult` gains a trailing defaulted `$resumeAt`, `NodeExecution` a trailing defaulted `$resumeAt`, and `NodeExecutor` a trailing defaulted `$maxInlineDelaySeconds` constructor argument.
- Resuming is retry-safe. If the queue is down when the coordinator is enqueued AFTER the timer flip committed, the run would otherwise be stuck for good; `resume_at` is therefore kept after the resume (as the time the timer was due), and a retry or duplicate of the resume job that finds a completed timer re-enters the idempotent coordinator instead of doing nothing.
- The sweeper also recovers a completed timer whose run stalled (no retry coming — a `--sync` sweep, or retries exhausted): a `running` run with no node `running` for over a minute (whether nodes are `pending` or the timer was the last work and only the finalize is missing), whose timer already succeeded. `TimerRepository::dueTimers()` returns those after the due `paused` ones.
- A timer's `finished_at` / `duration_ms` include the wait: taken after the inline sleep on a synchronous run, and recomputed from `started_at` when a queued timer resumes.
- A timed pause is never node-cached, even when it completes inline on a synchronous run: a cache hit would serve an immediate success and skip both the handler and the delay.
- A timer is not an approval: `flow:resume-due-timers` and the resume job only ever touch a `paused` node that has a `resume_at`, so an approval-paused node is never resumed by them, and a cancelled run's node (already `failed`) is left alone.
- On `queue.default=sync` a delayed job runs at once, before the timer is due, and stops; the timer then waits for `flow:resume-due-timers`. Use a real queue driver and schedule the command in production.

## [2.5.0] — 2026-08-31

### Added

- **Host-supplied read scope on the dashboard (`@api`)**: `Contracts\DashboardReadScope` lets a host constrain every query the dashboard issues, and `FlowDashboardReadModel::withScope()` returns a copy carrying it. This package defines no tenant column — none of its migrations do — so it cannot filter on one; a host that has added its own boundary supplies the predicate and stays authoritative about its own schema, which matters because a host that has NOT added the column must never be handed a query referencing it. The scope is applied to all five base queries (runs, run nodes, audit, approvals, webhook outbox) rather than only to the run list: scoping the list alone would still let a caller read an excluded run's detail by id, and the detail is where the payloads are. `withScope()` is a wither rather than a setter because the read model is immutable and bound as a singleton — a host wires it through `$app->extend(...)`, and rebuilding the instance by hand there would silently drop the configured connection.

### Notes

- Purely additive and inert by default. With no scope wired, every query is byte-for-byte the one it was before, which is the correct behaviour for a deployment with no boundary to enforce. Passing `null` clears a previously applied scope.
- An implementation returning the builder unmodified means "no restriction". An implementation that cannot resolve its subject must therefore add an always-false constraint rather than returning early, or a failed lookup silently widens into an unrestricted read.
- The read model is a singleton, so a scope is wired once and reused for the life of the container. Implementations MUST resolve their subject inside `apply()` rather than capturing it at construction: under Octane, Swoole or a queue worker a captured subject outlives the request that resolved it and would be served to the next one.

## [2.4.0] — 2026-08-26

### Added

- **Provenance / taint analysis (`@api`)**: a node port now declares where its authority comes from. An output carries a `Node\PortProvenance` — `Untrusted` (a taint source: a model completion, a fetched page, an ingested mail body), `Derived` (the default: untrusted in, untrusted out) or `Trusted` (an explicit, accountable sanitization claim) — and an input can declare `requiresTrusted: true` to refuse untrusted data outright. `Provenance\TaintAnalyzer::analyze()` propagates taint across the graph and returns a `Provenance\TaintMap`; `violations()` returns the wires that carry untrusted data into a port that refuses it, each with the `Provenance\TaintPath` from the originating source. `GraphValidator` now runs that check and **rejects such a graph at publish time**, with a message naming the origin and the full route (`llm.text -> format.out -> shell.command`) because the fix is almost never at the sink. Because a graph's wiring is stored data that nothing rewires at run time, this analysis is complete for the property it checks rather than best-effort. Exposed as `php artisan flow:taint {name} [--version=] [--json]`, which exits non-zero on violations so it doubles as a CI gate for definitions stored before the analysis existed.
- **Catalog projection carries the declarations**: `PortDefinition::toArray()` gains `provenance` and `requires_trusted`, so a visual editor can warn about a wire before the author reaches the validator. Additive keys on an existing projection; no key was renamed or removed.

### Notes

- Existing graphs are unaffected: every port defaults to `Derived` / `requiresTrusted: false`, which is exactly the behaviour of a codebase with no provenance model. Nothing is rejected until something is declared.
- `GraphValidator`'s constructor gains an optional trailing `?TaintAnalyzer` (defaulted), so existing `new GraphValidator($registry)` call sites are unchanged.

## [2.3.0] — 2026-08-26

### Added

- **Forensic run bundle + deterministic routing replay (`@api`)**: `Forensics\ForensicExporter::export()` produces a self-contained, content-addressed `Forensics\ForensicBundle` for one run — the run row, its stored graph definition at the exact version it executed, every node record in sequence with inputs/outputs, and the audit trail — digested with a canonical JSON encoding so the bundle proves it was not edited after export. `Forensics\ForensicVerifier::verify()` returns a `Forensics\ForensicReport` of four independent checks (`CHECK_DIGEST`, `CHECK_DEFINITION`, `CHECK_SEQUENCE`, `CHECK_ROUTING`); the routing check re-runs the real `InputRouter` over the recorded node outputs and names the divergent node when the replayed inputs disagree with what was recorded. Node bodies are never re-executed — only the pure router between them is replayed, which is what makes the check deterministic. `Forensics\ForensicFinding::UNVERIFIABLE` is a first-class third state alongside `OK`/`FAILED`: a redacted bundle honestly reports routing as unverifiable rather than claiming a pass it cannot substantiate. Exposed as `php artisan flow:forensics {runId} --output= --raw --verify=`, and bound in the container (exporter with the configured `PayloadRedactor`, verifier with the `NodeRegistry`).

## [2.2.2] — 2026-08-25

### Fixed

- **Docs-site dependency audit**: resolved the high-severity advisories reported by `npm audit` in the documentation toolchain (adm-zip 0.6, sharp 0.35, protobufjs 7.6.5, linkify-it 5.0.2). No package source or runtime behavior changed.

## [2.2.1] — 2026-08-24

### Fixed

- **BC of the v2.2.0 dashboard DTOs**: `Dashboard\RunSummary::$subject` was added mid-constructor without a default, breaking every positional pre-2.2 construction (`ArgumentCountError` in consumers such as flow-ai's Advisor tests and flow-admin's read model). `subject` is now the TRAILING constructor parameter with a `null` default on both `RunSummary` and `RunFilter`; a contract regression test pins that the pre-2.2 positional arity keeps constructing. No behavior change for named-argument callers.

## [2.2.0] — 2026-08-24

### Added

- **Run subject (`@api`)**: `FlowExecutionOptions` (and `FlowRun`) gain an optional `subject` — the identity the run acts FOR (e.g. an IAM subject reference like `user:42`) when a run is started on behalf of someone, such as an agent-initiated or delegated execution. Persisted on `flow_runs.subject` (new nullable, indexed column via the `2026_08_23_000001_add_subject_to_flow_runs_table` migration), immutable after the run row is created, inherited by replays (both `FlowEngine::replay()` and `flow:replay`) unless the caller re-states it. This is the sanctioned home for run identity: `flow_runs.input` is persisted unredacted, so identity and tokens must never travel through the run input. Exposed on the dashboard read contract too: `Dashboard\RunSummary::$subject` and a `Dashboard\RunFilter::$subject` exact-match filter, so a companion dashboard (laravel-flow-admin) can show and filter WHO each run acted for.

## [2.0.0] — 2026-07-18

Flow 2.0 unifies step and node persistence and adds a full graph execution engine alongside the unchanged v1 linear engine. **The v1 authoring and execution API is observably identical** — the fluent builder (`Flow::define()->step()->…->register()`), the engine methods (`execute`/`dryRun`/`dispatch`/`resume`/`reject`), and v1 semantics (step ordering, compensation order, approval resume) are preserved. See [`docs/UPGRADE.md`](docs/UPGRADE.md) for the complete, line-by-line breaking + additive `@api` reference and the migration steps.

### Added

- **Graph execution engine**: a new `@api` authoring/serialization surface — `Node\*` node contract (typed ports, `#[Cost]`/`#[Retry]`/`#[Cacheable]` attributes), `Graph\*` (`GraphDefinition`, `GraphValidator`, `GraphSerializer`, `StoredDefinition`, `DefinitionSigner`, `GraphTransfer`), and `Contracts\DefinitionRepository` for stored, versioned, publishable flow definitions — plus the internal executors that run graphs synchronously or on the queue (invoke through the `Flow` facade, not the executor classes directly). Version-exact **replay** re-executes a pinned graph run at its exact stored version; a built-in approval-gate node pauses/resumes a run.
- **Dashboard mutation seams (`@api`)** for a companion operator UI: `FlowEngine::redeliverWebhook(int $outboxId): bool`, `cancel(string $runId, array $actor = []): FlowRun`, `replay(string $runId, ?FlowExecutionOptions $options = null): FlowRun`, and `resumeByHash(string $tokenHash, array $payload = [], array $actor = []): FlowRun` / `rejectByHash(...)` (decide an approval by its stored SHA-256 hash — the plaintext token is never recoverable from storage), each mirrored on the `Flow` facade.
- **Approval lifecycle (`@api`)**: `ApprovalRepository::expirePendingForRun()` (a cancelled run's pending approvals are expired in the abort transaction); `Dashboard\ApprovalSummary::$tokenHash`; public hash-native `ApprovalTokenManager` methods (`findByHash`/`approveForRunStatusByHash`/`rejectForRunStatusByHash`).
- **Read model (`@api`)**: `FlowDashboardReadModel::stepCounts(array $runIds): array` (batched, N+1-free), `Dashboard\StepSummary::$cacheHit`, `Contracts\RunNodeRepository::terminate()` (compare-and-set node termination).
- **Distinct persistence-outage exception (`@api`)**: `Exceptions\PersistenceUnavailableException` (a subtype of `FlowExecutionException`, and now parent of `ApprovalPersistenceException`), raised by `cancel`/`replay`/`redeliverWebhook`/approvals on a DB outage so consumers can distinguish an infrastructure failure (retryable / HTTP 503) from a state conflict (HTTP 409).
- **Opt-in graph run/node broadcasting (`@api`)**: `laravel-flow.broadcasting.enabled` + `NodeTransitioned` / `GraphRunProgressUpdated` events on a private per-run channel (emit-only; the host authorizes the channel). Disabled by default.
- **Static dry-run planner (`@api`)**: `Executor\DryRun\DryRunPlanner` returns a Kahn-wave execution plan + cost estimate, executing no handler and writing zero rows.

### Changed / Breaking (internal persistence surface only)

- `flow_steps` is retired and replaced by `flow_run_nodes` (a superset with graph/retry/cache columns; a v1 step is a `node_type = 'legacy.step'` row). `Contracts\StepRunRepository` → `Contracts\RunNodeRepository`; `FlowStore::steps()` → `FlowStore::runNodes()`; `Models\FlowStepRecord`/`Persistence\EloquentStepRunRepository` removed in favor of the run-node equivalents. Applications that only use the fluent builder + facade are unaffected; only custom `FlowStore` implementers must migrate. The `Dashboard\*` read contract and every `FlowDashboardReadModel` signature are unchanged.

## [1.1.1] — 2026-06-21

### Fixed

- Laravel concurrency-driver CI compatibility.

### Changed

- Optimized documentation/site images (ImgBot).

## [1.1.0] — 2026-06-20

### Added

- docmd documentation site (`docs-site/`) with an enterprise "wow" intro — banner, problem/solution framing, moats, and a competitor matrix.
- README banner and a "web admin UI" section; comparison-table rows for webhook delivery, the dashboard, and the stable `@api` surface.

## [1.0.0] — 2026-05-05

### Added

- **Package-side dashboard contracts** under `Padosoft\LaravelFlow\Dashboard\*`:
  - `FlowDashboardReadModel` exposing `listRuns(RunFilter, Pagination)`, `findRun(id)`, `pendingApprovals(limit)`, `listApprovals(ApprovalFilter, Pagination)`, `failedWebhookOutbox(limit)`, `pendingWebhookOutbox(limit)`, `listWebhookOutbox(WebhookOutboxFilter, Pagination)`, and aggregated `kpis()` (single-query conditional sums).
  - Read DTOs: `RunSummary`, `StepSummary`, `AuditEntry`, `ApprovalSummary`, `WebhookOutboxSummary`, `RunDetail`, `RunFilter`, `ApprovalFilter`, `WebhookOutboxFilter`, `Pagination`, `PaginatedResult`, `Kpis`.
  - Authorization hook: `DashboardActionAuthorizer` interface plus `DenyAllAuthorizer` (default registered binding, deny-by-default) and `AllowAllAuthorizer` (explicit dev opt-in).
- **`@api` / `@internal` contract markers** across 81 source files. `@api` covers Facade, FlowEngine, builder/DTOs, Events, Exceptions, Contracts, Dashboard, WebhookDeliveryClient/Result. `@internal` covers Persistence, Models, Queue, Jobs, Console.
- **Migration helpers**:
  - [`docs/UPGRADE.md`](docs/UPGRADE.md) — v0.1 → v1.0 upgrade chain plus the SemVer policy on the `@api` surface.
  - [`docs/MIGRATION_DURABLE.md`](docs/MIGRATION_DURABLE.md) — concept mapping from Temporal-style durable workflow runtimes.
  - [`docs/MIGRATION_SYMFONY.md`](docs/MIGRATION_SYMFONY.md) — concept mapping from `symfony/workflow` state machines.
- **Companion app spec** [`docs/DASHBOARD_APP_SPEC.md`](docs/DASHBOARD_APP_SPEC.md) — self-contained brief for the separate `padosoft-laravel-flow-dashboard` repo.
- **Contract tests** (`tests/Contract/PublicApiContractTest.php`, new `Contract` testsuite) pinning class existence, `@api` docblock tag, public method names, and constants for the v1.0 surface.

### Changed

- **`DashboardActionAuthorizer` default** is now `DenyAllAuthorizer` so production cannot accidentally expose the dashboard. Host applications must explicitly bind their own implementation (or `AllowAllAuthorizer` for development).
- **`FlowDashboardReadModel::kpis()`** uses single aggregated SELECTs with conditional sums for run and outbox counts (was 8 separate `COUNT(*)` queries) and counts compensated runs by the `compensated` boolean column instead of `status='compensated'` (catches runtime-abort runs with `status='aborted', compensated=true`).
- **`composer test`** now runs three testsuites: Unit + Architecture + Contract.

### Fixed

- Dashboard audit reader falls back to `created_at` when `flow_audit.occurred_at` is null instead of fabricating a fresh `DateTimeImmutable` per request, preserving deterministic timeline ordering for legacy/manual rows.

### Notes

- No new database migrations for v1.0. The dashboard read model queries existing v0.2 / v0.3 tables.

## [0.3.0] — 2026-05-05

### Added

- **Approval gate primitive** — `approvalGate($name)` step type that pauses runs, persists `paused` run/step/audit state, and (when persistence is enabled) issues a hashed approval record.
- **Hashed approval-token foundation** — `ApprovalTokenManager` issues expiring, one-time approval records and persists only SHA-256 token hashes; the plain token is returned only on the immediate `FlowRun`.
- **Persisted resume/reject API** — `Flow::resume($plainToken, $payload, $actor)` and `Flow::reject($plainToken, $payload, $actor)` consume approval tokens under per-run shared cache lock; duplicate resumes return current `running` state instead of re-entering downstream handlers; definition drift is checked before consuming pending tokens; older approved tokens can reissue a downstream-gate token once without invalidating the previous hash.
- **CLI approval commands** — `flow:approve {token}` and `flow:reject {token}` with shared decision plumbing and persistence-backed CLI tests.
- **Signed webhook outbox delivery** — lifecycle rows for `flow.completed`, `flow.failed`, `flow.paused`, and `flow.resumed` are persisted in engine transactions; `flow:deliver-webhooks` signs payloads with HMAC-SHA256 in an `X-Laravel-Flow-Signature: t=...,v1=...` header, leases pending/stale-delivering rows with attempts compare-and-set guard, and reschedules transient failures with exponential backoff up to a configured retry limit.
- Configuration: `approval.token_ttl_minutes`, `webhook.enabled`, `webhook.url`, `webhook.secret`, `webhook.retry_base_delay_seconds`, `webhook.max_attempts`, `webhook.timeout_seconds`.
- Migrations: `flow_approvals` and `flow_webhook_outbox` tables (additive, cascade with `flow_runs`); follow-up migration adds `previous_token_hash` column for downstream-gate token reissue.

### Notes

- `Flow::resume()` and `Flow::reject()` require a shared cache lock store; the process-local `array` store is rejected.
- Plain approval tokens are never recoverable from storage. Operators must receive tokens out-of-band (email, Slack, signed webhook payload).

## [0.2.0] — 2026-05-04

### Added

- **Opt-in DB persistence** — `flow_runs`, `flow_steps`, `flow_audit` migrations and Eloquent repositories. Engine writes synchronous run/step/audit transitions when `persistence.enabled=true` for non-dry-run executions. Public contracts: `FlowStore`, `RunRepository`, `StepRunRepository`, `AuditRepository`, `RedactorAwareFlowStore`, `CurrentPayloadRedactorProvider`.
- **Payload redaction** — JSON payloads pass through a configurable redactor before storage; default keys cover common secret-looking fields. Append-only audit guard at the model layer prevents bulk update/delete.
- **Idempotency keys and correlation IDs** — `FlowExecutionOptions` carries normalized identifiers; synchronous `Flow::execute()` reuses an existing persisted run for a given idempotency key + definition (with create-race fallback).
- **Retention pruning** — `flow:prune` deletes terminal runs older than the configured retention window plus their child rows, in chunked transactions.
- **Queued dispatch foundation** — `Flow::dispatch($name, $input, $options)` validates the flow and queues an after-commit `RunFlowJob` with per-dispatch cache locking, database queue coverage, and guarded Laravel-native tries/backoff metadata.
- **Terminal-run replay** — `flow:replay {runId}` creates a new persisted run linked via `replayed_from_run_id` and warns on definition drift; replay metadata is stored via additive migration.
- **Parallel compensation strategy** — `compensation_strategy=parallel` batches completed compensators through Laravel Concurrency for independent compensators; reverse-order remains the default.
- Configuration: `persistence.enabled`, `persistence.redaction.*`, `persistence.retention.days`, `queue.lock_store`, `queue.lock_seconds`, `queue.lock_retry_seconds`, `queue.tries`, `queue.backoff_seconds`, `compensation_strategy`, `compensation_parallel_driver`.

### Changed

- **Baseline compatibility policy** — Composer constraints and CI target Laravel 13 only, with PHP 8.3 and 8.4 as stable hard gates. Package quality commands are exposed through Composer scripts: `format:test`, `analyse`, `test`, `quality`.
- **Runtime dependencies** — `illuminate/database`, `illuminate/console`, `illuminate/cache`, and `illuminate/queue` are now production dependencies because v0.2 persistence repositories, console commands, queued dispatch, and run locks are part of the package runtime surface.

### Notes

- Audit rows are append-only at runtime; they remain prunable via `flow:prune`.
- Synchronous listener / repository failures are rethrown after best-effort recovery and compensation.

## [0.1.0] — 2026-05-02

### Added

- **W5 — full scaffold expansion + initial Flow engine core.**
  - **Scaffold completion.** Full `.claude/` vibe-coding pack imported from the Padosoft baseline (skills, rules, agents, commands, instructions); `.github/workflows/ci.yml` matrix on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 with Pint + PHPStan + PHPUnit Unit + Architecture suites; `phpunit.xml` Unit + Architecture + opt-in Live testsuite split; `pint.json` + `phpstan.neon.dist` aligned with the Padosoft baseline; `config/laravel-flow.php` with five tunables (`default_storage`, `audit_trail_enabled`, `dry_run_default`, `step_timeout_seconds`, `compensation_strategy`); `LaravelFlowServiceProvider` registers the engine as a container singleton and publishes the config under the `laravel-flow-config` tag; `composer.json` trimmed to Laravel 12 / 13 + PHP 8.3 minimum and aligned with the Padosoft package baseline; `.editorconfig` + `.gitattributes` shipped; README rewritten as a 14-section WOW document covering theory, comparison vs Spatie Workflow / Symfony Workflow / Temporal / AWS Step Functions, installation, quick start, usage examples, configuration reference, architecture diagram, AI vibe-coding pack section, testing strategy, and roadmap.
  - **Core engine.** `FlowEngine` (in-memory definition registry + execute / dryRun + reverse-order compensation walker), `FlowDefinitionBuilder` (fluent API: `withInput()`, `step()`, `withDryRun()`, `compensateWith()`, `withAggregateCompensator()`, `register()`), `FlowDefinition` + `FlowStep` (readonly DTOs), `FlowStepHandler` + `FlowCompensator` (interfaces resolved through the Laravel container), `FlowContext` (readonly carrier with input + accumulated step outputs + dry-run flag), `FlowStepResult` (readonly DTO with success / output / error / businessImpact / dryRunSkipped), `FlowRun` (status machine: pending / running / succeeded / failed / compensated / aborted, plus failedStep / compensated / stepResults / startedAt / finishedAt), `Facades\Flow` exposing the engine.
  - **Exceptions.** `FlowException` (non-final base extending `RuntimeException`), `FlowInputException`, `FlowNotRegisteredException`, `FlowExecutionException`, `FlowCompensationException`.
  - **Events.** `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated` — audit trail emitted via the Laravel event dispatcher; can be globally muted via `audit_trail_enabled = false`.
  - **Test suite.** Unit suite covering builder fluency + register error paths (`FlowDefinitionBuilderTest`), happy-path execution + input validation + dry-run skip semantics + uuid generation + step output accumulation (`FlowEngineTest`), reverse-order compensation + no-compensator-on-first-step + payload pass-through (`FlowEngineCompensationTest`), event emission per transition + dry-run flag propagation + audit-disabled silencing (`FlowEventEmissionTest`), Facade round-trip (`FlowFacadeTest`); architecture test (`StandaloneAgnosticTest`) walks `src/` recursively with `RecursiveDirectoryIterator` and asserts no AskMyDocs / sister-package symbols leak into production code; opt-in Live placeholder under `tests/Live/`.

### Changed

- **`LaravelFlowServiceProvider`** — was a no-op skeleton; W5 ships the real bindings (engine singleton + config publish).
- **`composer.json`** — dropped `^11.0` from the `illuminate/*` requires (v4.0 minimum is Laravel 12); dropped `orchestra/testbench: ^9.0`; added the `Flow` Facade alias under `extra.laravel.aliases`; added a `suggest` entry for `padosoft/laravel-patent-box-tracker` (R&D activity tracking on repos that depend on `laravel-flow`).
- **`README.md`** — replaced 49-line draft with a 500+ line WOW document.

### Removed

- N/A.

[1.0.0]: https://github.com/padosoft/laravel-flow/releases/tag/v1.0.0
[0.3.0]: https://github.com/padosoft/laravel-flow/releases/tag/v0.3.0
[0.2.0]: https://github.com/padosoft/laravel-flow/releases/tag/v0.2.0
[0.1.0]: https://github.com/padosoft/laravel-flow/releases/tag/v0.1.0
