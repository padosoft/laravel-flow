# Laravel Flow Enterprise Plan

## Summary

Bring `padosoft/laravel-flow` from v0.1 package core to an enterprise-grade Laravel workflow engine with persisted runs, queued execution, deterministic replay, approval gates, webhooks, a companion dashboard app, migration helpers, and release-quality documentation.

Current baseline after fetch:

- `origin/main`: `208a9d1`
- Tag: `v0.1.0`
- v0.1 status: code complete for the in-memory engine core.
- Enterprise target selected by user: Laravel 13-only, PHP `^8.3`, after Macro Task 1 narrows Composer and CI.
- Dashboard selected by user: companion app.

## Macro Task 0 - Durable Agent Operating System

Branch macro: `task/agent-operating-system`

Subtasks:

- `task/agent-docs-bootstrap`: add `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, `docs/LESSON.md`, `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `.github/copilot-instructions.md`, the laravel-flow enterprise Claude skill/rule, and CI triggers for PRs targeting `task/**`.
- `task/import-reference-lessons`: adapt the useful workflow lessons from `product_image_discovery_admin` without copying app-specific implementation rules.

Guardrails:

- No runtime package code changes in this macro unless required by docs tooling.
- Every future session starts by reading the restart files.
- Every background agent receives `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `docs/RULES.md`, `docs/LESSON.md`, and `.claude/skills/laravel-flow-enterprise/SKILL.md`.

Tests:

- Composer validation.
- Pint check.
- PHPStan.
- PHPUnit Unit and Architecture suites.

## Macro Task 1 - Baseline, Truthfulness, Tooling

Branch macro: `task/baseline-tooling-laravel13`

Key changes:

- Narrow Composer and CI from Laravel 12/13 to Laravel 13-only.
- Reconcile README, badges, roadmap, and package metadata with the real v0.1 state.
- Keep PHP `^8.3`; test PHP 8.3 and 8.4 as stable gates, with PHP 8.5 only if dependency support is reliable.
- Ensure PHPStan, Pint, Unit, and Architecture are hard CI gates.
- Add Composer scripts for `test`, `analyse`, `format:test`, and `quality`.

Tests:

- Composer validation on narrowed constraints.
- PHPStan with configured paths.
- Unit and Architecture suites.
- README test-count sync when counts change.

## Macro Task 2 - v0.2 Persistence Layer

Branch macro: `task/v02-persistence`

Public interfaces:

- `FlowStore`
- `RunRepository`
- `StepRunRepository`
- `AuditRepository`

Key changes:

- Add publishable migrations for `flow_runs`, `flow_steps`, and `flow_audit`.
- Add Eloquent-backed repositories and models where useful.
- Persist run input, output, status, failed step, compensation state, business impact, audit events, timestamps, durations, correlation id, and idempotency key.
- Keep persistence opt-in/configurable so the in-memory v0.1 path remains usable.
- Add retention/prune command.

Guardrails:

- Audit trail is append-only in normal runtime.
- No secrets in stored payloads without explicit redaction hooks.
- SQLite must pass tests; schema should remain MySQL/Postgres-friendly.

Tests:

- Migration up/down.
- Persisted success/failure/compensation flows.
- Audit immutability.
- Idempotency/correlation behavior.

## Macro Task 3 - v0.2 Queues, Replay, Compensation Strategies

Branch macro: `task/v02-queues-replay`

Public API:

- `Flow::dispatch(...)`
- `flow:replay {runId}`
- Compensation strategy config: `reverse_order`, `parallel`

Key changes:

- Queue-backed run execution.
- Run-level locking to prevent duplicate concurrent execution.
- Replay command that creates a new linked run without mutating the original.
- Parallel compensation strategy, opt-in only for safe independent compensators.
- Retry/backoff policy for queued steps.
- Laravel events and metadata suitable for Horizon/observability.

Guardrails:

- Replay must warn about definition drift.
- Compensation failures must be stored and auditable.
- Queue sync and database queue paths both need coverage.

Tests:

- Queue fake tests.
- Database queue integration tests.
- Replay command tests.
- Locking/concurrency tests.
- Parallel compensation tests.

## Macro Task 4 - v0.3 Approval Gates And Webhooks

Branch macro: `task/v03-approval-webhooks`

Public API:

- `ApprovalGate`
- `Flow::resume(string $token, array $payload = [])`
- `flow:approve {token}`

Key changes:

- Add a pauseable approval step type.
- Store only hashed, expiring, one-time resume tokens.
- Resume/reject service with actor metadata.
- CLI approval/reject commands.
- Webhook outbox for `flow.paused`, `flow.resumed`, `flow.failed`, and `flow.completed`.
- HMAC webhook signatures, retry/backoff, and redacted payloads.

Guardrails:

- Tokens are never stored in clear text.
- Resume is idempotent.
- Webhook delivery failures cannot corrupt run state.

Tests:

- Pause/resume/reject/expiry.
- Token hashing and one-time use.
- Webhook signature verification.
- Outbox retry behavior.

## Macro Task 5 - Companion Dashboard App

Package branch macro: `task/dashboard-contracts`

Companion app branch/repo:

- Preferred repo: `padosoft-laravel-flow-dashboard`
- Local sibling default: `../padosoft-laravel-flow-dashboard`

Package key changes:

- Stable read models for runs, steps, audit, approvals, and replay actions.
- Configurable middleware/policy hooks.
- No embedded package UI.

Companion app key changes:

- Laravel 13 app consuming the package through a Composer path repository during development.
- Dense operator dashboard: run list, run detail, audit timeline, failed compensation view.
- Replay action with confirmation and feedback.
- Approval queue with resume/reject controls.
- KPIs for failures, compensation failures, paused approvals, and webhook failures.

UI guardrails:

- Operational UI, not a landing page.
- No nested cards.
- Border radius <= 8px.
- Tables, drawers, modals, toasts.
- No secrets in JSON or UI.

Tests:

- Package PHPUnit/PHPStan/Pint.
- Companion PHPUnit, Vitest, Vite build, Playwright.
- Playwright scenarios for list, detail, replay, approval resume/reject, and failed compensation.

## Macro Task 6 - v1.0 Stable API And Migration Helpers

Branch macro: `task/v10-stable-api-migrations`

Key changes:

- Mark public API and internal namespaces.
- Add SemVer and backwards-compatibility policy.
- Add migration guides/helpers for Spatie Workflow and Symfony Workflow.
- Add v0.1 -> v0.2 -> v0.3 -> v1.0 upgrade docs.
- Add contract tests pinning builder, DTO, facade, config, migrations, commands, and events.

Guardrails:

- No heavy optional migration dependency in the default install.
- No internal class documented as stable API.
- Breaking changes only before v1.0.

Tests:

- Public contract tests.
- Migration helper tests.
- Full package quality gates.

## Macro Task 7 - Enterprise README, Release, Tag

Branch macro: `task/release-docs-v1`

Key changes:

- Produce a complete README inspired by the AskMyDocs style: badges, architecture, feature matrix, install, config, quickstart, API examples, roadmap, quality gates, security, and enterprise notes.
- Add docs for architecture, persistence, queues, approvals, webhooks, dashboard, migration guides, and release process.
- Read `docs/LESSON.md` and fold reusable lessons back into `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, and repo skills.
- Add final CHANGELOG and release checklist.
- Tag `v1.0.0` and create GitHub release only from `main`.

Guardrails:

- README must not promise unavailable behavior.
- Test/assertion counts must match actual PHPUnit output.
- README section `Comparison vs alternatives` must be reviewed for every feature addition or material feature improvement; research competitor behavior before changing uncertain comparison claims.
- Release only after local gates, CI, and Copilot review are clean.

## PR Loop Standard

For every subtask:

1. Create a branch from the macro branch.
2. Implement one coherent slice.
3. Update `docs/PROGRESS.md`.
4. Update `docs/LESSON.md` if anything reusable was learned.
5. Run relevant local gates.
6. Open PR into the macro branch.
7. Request GitHub Copilot Code Review.
8. Wait for CI and review. CI is expected to run for PRs targeting `main` and `task/**`; `task/**` is intentionally PR-base scope only because macro and subtask branches both use the `task/` prefix.
9. Fix all failures/comments.
10. Repeat until clean.
11. Merge.

For every macro:

1. Open PR from macro branch into `main`.
2. Run the same CI/Copilot loop.
3. Merge only when clean.

## Assumptions

- Laravel 13-only is the desired enterprise target, but implementation must stay Laravel 12/13-compatible until Macro Task 1 changes Composer and CI.
- The dashboard remains a companion app unless the user explicitly changes the architecture.
- Package code remains standalone-agnostic.
- GitHub/Copilot access must be verified, not assumed.
