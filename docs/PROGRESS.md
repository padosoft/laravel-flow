# Progress

## 2026-05-03 - Durable Handoff

This file is a durable handoff summary, not a per-poll CI/Copilot log. Detailed PR iteration history belongs in the relevant GitHub PR.

Known workstreams:

| Workstream | Durable state |
| --- | --- |
| Macro Task 0 - durable agent operating system | Completed after merge of the macro PR to `main`. |
| Macro Task 1 - baseline tooling and Laravel 13 policy | Completed after merge of the macro PR to `main`; Composer/CI/docs now narrow to Laravel 13, PHP 8.3/8.4, and Composer-script quality gates. |
| Macro Task 2 - v0.2 persistence layer | Completed after merge of the macro PR to `main`; the package has opt-in DB persistence for runs, steps, audit rows, redaction, retention pruning, correlation IDs, and idempotency keys. |
| Macro Task 2 macro review hardening | Centralizes execution-scoped redactor provider resolution, aligns prune transaction callback usage, explicitly deletes pruned child rows, and keeps persistence model PHPDoc aligned with stored timestamp columns so Macro Task 2 review feedback remains folded into the durable implementation. |
| Macro Task 3 - v0.2 queues/replay | Completed after merge of the macro PR to `main`: `Flow::dispatch()` validates and queues an after-commit `RunFlowJob` carrying flow name, input, execution options, per-dispatch lock metadata, and optional guarded Laravel-native tries/backoff metadata; queued jobs release lock-held duplicates after a configurable short delay, no-op completed duplicates, reject process-local `array` locks outside the `sync` queue driver, and have sync/database queue coverage. `flow:replay {runId}` creates new linked terminal-run replays with additive lineage metadata and partial-schema failures. `compensation_strategy=parallel` batches independent compensators while preserving `reverse-order` as the default. |
| Macro Task 4 - v0.3 approval gates/webhooks | Completed after merge of macro PR #32 into `main` (merge commit `7fca1461083d8abb7e054baa32f6b2665a0581f6`). The macro adds `approvalGate($name)` pause primitive, hashed one-time `ApprovalTokenManager` tokens, persisted `Flow::resume()` / `Flow::reject()` with per-run shared cache lock, `flow:approve` / `flow:reject` CLI commands, and `flow:deliver-webhooks` with HMAC-SHA256 signed outbox delivery (lease-based `claimNextPending`, `markDeliveryResult`, configurable timeout/retries). Lifecycle outbox rows (`flow.paused`, `flow.resumed`, `flow.completed`, `flow.failed`) persist in engine transactions. Additive migrations for `flow_approvals` and `flow_webhook_outbox` cascade with `flow_runs`. |
| Macro Task 5 - companion dashboard contracts (package-side) | Completed after merge of macro PR #34 into `main` (merge commit `0191b61f48031e48bc30021e8034bfafba1839ed`). The macro adds the headless `Padosoft\LaravelFlow\Dashboard\*` namespace: `FlowDashboardReadModel` with paginated `listRuns`/`findRun`/`listApprovals`/`pendingApprovals`/`listWebhookOutbox`/`failedWebhookOutbox`/`pendingWebhookOutbox`/`kpis`, immutable read DTOs (`RunSummary`, `StepSummary`, `AuditEntry`, `ApprovalSummary`, `WebhookOutboxSummary`, `RunDetail`, `RunFilter`, `ApprovalFilter`, `WebhookOutboxFilter`, `Pagination`, `PaginatedResult`, `Kpis`), and the `DashboardActionAuthorizer` interface with `DenyAllAuthorizer` registered as the deny-by-default binding (plus `AllowAllAuthorizer` for explicit dev opt-in). The companion app spec lives at `docs/DASHBOARD_APP_SPEC.md` and is intentionally outside the package repo. |
| Macro Task 6 - v1.0 stable API and migration helpers | Completed after merge of macro PR #36 into `main` (merge commit `856f824da61c83bda7e8cc38ec9517a98c32b042`). The macro marks 81 source files with class-level `@api` (Facade, FlowEngine, builder/DTOs, Events, Exceptions, Contracts, Dashboard, WebhookDeliveryClient/Result) or `@internal` (Persistence, Models, Queue, Jobs, Console). Adds `docs/UPGRADE.md`, `docs/MIGRATION_DURABLE.md`, `docs/MIGRATION_SYMFONY.md`, and `tests/Contract/PublicApiContractTest.php` (new `Contract` testsuite) pinning the v1.0 surface so future patches cannot silently drop or rename `@api` classes/methods/constants. `composer test` now runs Unit + Architecture + Contract suites. |

Concurrent subtasks should add rows here instead of replacing existing workstreams.

To resume live work:

- Run `git status --short --branch`.
- Run `gh pr list --state open --json number,title,headRefName,baseRefName,url`.
- For any active PR, verify head, reviewer, mergeability, and CI with `gh pr view <PR> --json headRefOid,mergeable,statusCheckRollup,reviewDecision,reviews`.
- Use `gh api repos/<owner>/<repo>/pulls/<PR>/requested_reviewers`, or derive `<owner>/<repo>` with `gh repo view --json nameWithOwner --jq .nameWithOwner`.

Completed in Macro Task 0:

- Added durable restart files: `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, `docs/LESSON.md`, `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `.github/copilot-instructions.md`, `.claude/skills/laravel-flow-enterprise/SKILL.md`, and `.claude/rules/rule-laravel-flow-enterprise.md`.
- Imported/adapted useful Padosoft Claude pack guidance from the reference project without copying app-specific implementation rules.
- Updated CI so PRs targeting `main` or `task/**` run the matrix; push-trigger CI remains limited to `main` to avoid duplicate subtask runs.
- Recorded the durable rule that README section `Comparison vs alternatives` must be reviewed for every new or materially improved feature, with competitor research when claims are uncertain.
- Aligned README, CONTRIBUTING, PR template, Copilot instructions, repo rules, and repo skills around the macro/subtask workflow, the pre-Macro-1 Laravel 12/13 compatibility state, companion-dashboard scope, and mandatory Copilot review.

Macro Task 0 validation summary:

- Macro Task 0 was validated with:
  - `composer validate --strict --no-check-publish`
  - `vendor/bin/pint --test`
  - `vendor/bin/phpstan analyse --no-progress`
  - `vendor/bin/phpunit --testsuite Unit` => 32 tests, 97 assertions
  - `vendor/bin/phpunit --testsuite Architecture` => 2 tests, 7 assertions

Completed in Macro Task 2 (v0.2 persistence layer):

- Added one publishable migration file that creates `flow_runs`, `flow_steps`, and `flow_audit` with SQLite-tested schema and MySQL/Postgres-friendly indexes.
- Added public `FlowStore`, `RunRepository`, `StepRunRepository`, `AuditRepository`, `RedactorAwareFlowStore`, and `CurrentPayloadRedactorProvider` contracts.
- Added Eloquent-backed persistence repositories with redacted JSON payload storage, append-only audit protections, immutable run identity updates, and atomic step upserts.
- Wired the synchronous engine to persist opt-in run/step/audit transitions, business impact, output aggregates, failures, compensation state, timestamps, durations, correlation IDs, and idempotency keys.
- Added `FlowExecutionOptions` for normalized, length-validated correlation/idempotency metadata and idempotent persisted-run reuse with step-result rehydration and create-race fallback.
- Added `flow:prune` retention cleanup for old terminal runs while keeping pending/running rows intact.

Current active macro:

- Macro Task 7 — release docs + v1.0.0 tag (`task/release-docs-v1`). Adds `CHANGELOG.md`, expands README architecture/security/enterprise sections, folds reusable `docs/LESSON.md` findings back into AGENTS / CLAUDE / RULES / Copilot instructions / PR template / repo skills, and tags `v1.0.0` from `main` after the macro PR merges.

Current validation baseline:

- `composer validate --strict --no-check-publish`
- `composer format:test`
- `composer analyse`
- `composer test` => Unit 250 tests / 1125 assertions, Architecture 2 tests / 7 assertions, Contract 63 tests / 265 assertions
