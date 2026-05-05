# Changelog

All notable changes to `padosoft/laravel-flow` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0 onward, SemVer applies to source classes annotated with `@api`. Classes annotated `@internal` are not covered by the SemVer guarantee; see [`docs/UPGRADE.md`](docs/UPGRADE.md) for the full policy.

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
