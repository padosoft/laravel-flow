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
| Macro Task 4 - v0.3 approval gates/webhooks | In progress on macro branch `task/v03-approval-webhooks`. PR #25 added additive persistence foundation for hashed approval resume tokens and webhook outbox rows. PR #26 added the public `approvalGate($name)` pause primitive and persisted `paused` run/step/audit state. PR #27 added the approval repository and `ApprovalTokenManager` for hashed, expiring, one-time approve/reject token records. PR #28 wired persisted `approvalGate()` pauses to issue pending approval records while keeping plain tokens only on the returned run. The approval-resume API slice adds persisted `Flow::resume()` / `Flow::reject()` APIs before CLI approval commands and webhook delivery; local hardening keeps approval decision/run-claim operations behind optional repository extensions, rejects process-local approval locks, serializes approval decisions by run, forces pending lock losers to retry, validates definition drift before consuming pending tokens, preserves decided-token state around expiry races, rejects expired-token lock fallbacks, treats older gate tokens as read-only once later approval gates exist while allowing one fresh token reissue for a later paused approval gate without invalidating the previous hash, surfaces package-level schema/cache diagnostics, applies execution-frozen redaction to approval decisions, atomically claims paused runs before recovering already-succeeded approval steps, returns current `running` state instead of re-entering downstream handlers after lock expiry, and skips already-persisted downstream successes on retry when no handler execution is required. |
| Macro Task 4 - approval resume/reject API hardening | Completed and merged into `task/v03-approval-webhooks` via PR #29 (merge commit `a0eb54cc9c45e81f3350de3dd0336b52202f2c75`). The slice hardened approval resume/reject against downstream token reissue write failures, preserved running duplicates while downstream work is still in flight, kept persisted completed downstream steps finishable, and aligned `docs/LESSON.md` / README comparison wording. |
| Macro Task 4 - CLI approval/reject commands | Completed and merged into `task/v03-approval-webhooks` via PR #30 (merge commit `0dfee4a272f34c94ec6718f2d2fce56c05de17f9`). The slice added `flow:approve` / `flow:reject` commands, shared approval-decision command plumbing, service-provider registration, and persistence-backed CLI tests. |

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

Current validation baseline:

- `composer validate --strict --no-check-publish`
- `composer format:test`
- `composer analyse`
- `composer test` => Unit 212 tests / 965 assertions, Architecture 2 tests / 7 assertions

Current Macro Task 4 subtask:

- Approval resume/reject API hardening is merged. Continue with the signed webhook outbox delivery slice on `task/v03-approval-webhooks` (new subtask branch TBD); keep detailed iteration history in the new PR rather than this durable handoff file.
- The remaining Macro Task 4 slice after CLI approval/reject commands is signed webhook outbox delivery.

Next active macro:

- Continue Macro Task 4 from `docs/ENTERPRISE_PLAN.md`: after the approval resume API slice lands, add CLI approval/reject commands and signed webhook outbox delivery in follow-up subtasks.
