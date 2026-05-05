---
name: laravel-flow-enterprise
description: Use when working on padosoft/laravel-flow enterprise roadmap tasks, including persistence, queue/replay, approval gates, webhook outbox, companion dashboard contracts, API stability, release docs, or any restart after context loss.
---

# Laravel Flow Enterprise Skill

## First Step In Any Session

Read, in order:

1. `docs/PROGRESS.md`
2. `docs/ENTERPRISE_PLAN.md`
3. `docs/RULES.md`
4. `docs/LESSON.md`
5. `AGENTS.md`

Then run:

```bash
git status --short --branch
git log --oneline --decorate -5
```

If the current branch or progress file disagree, trust Git first and update `docs/PROGRESS.md`.

## Product Defaults

- Current implementation target: active Composer/CI matrix compatibility.
- Active matrix after Macro Task 1: Laravel 13 and PHP `^8.3`, with CI hard gates on PHP 8.3 and 8.4.
- Laravel 13-only APIs are allowed only when covered by package tests and compatible with the current Composer constraints.
- Dashboard: companion app.
- Core package: standalone-agnostic and headless.

## Work Breakdown

- Macro branches are long enough to collect related subtasks.
- Subtask branches implement one coherent slice and PR into the macro branch.
- Macro branches PR into `main` only after all subtask PRs are merged.

## Required Documentation Updates

Update `docs/PROGRESS.md` after:

- creating/switching branches
- implementing a slice
- running test gates
- opening/requesting/merging PRs
- hitting blockers

For concurrent subtasks, keep detailed CI/Copilot iteration history in the PR and write only durable restart state to the shared progress file.

Update `docs/LESSON.md` after reusable findings from:

- reusable lessons extracted from Copilot comments
- CI failures with reusable cause
- local environment discoveries
- API/design decisions that future agents must preserve
- security, redaction, queue, replay, or migration edge cases

Update `README.md` section `Comparison vs alternatives` whenever a feature is
added or materially improved. If a competitor row is uncertain, research that
package/product first and keep the comparison accurate rather than speculative.

## Quality Gates

For package-only changes:

```bash
composer validate --strict --no-check-publish
composer format:test
composer analyse
composer test
```

For companion dashboard app/repo changes, add that app's PHP, Node, Vite, Vitest, and Playwright gates. Package-only dashboard contracts in this repository stay on the package gates plus any contract tests.

## PR Loop

Use `.claude/skills/copilot-pr-review-loop/SKILL.md` for the mandatory remote
loop after choosing the correct target branch:

- subtask PRs target the active macro branch, usually `task/<macro-name>`
- macro PRs target `main`

Do not mark a task done until:

- local gates are green
- PR exists against the correct target branch
- Copilot Code Review was requested and completed
- CI is green when GitHub reports checks for that PR; if GitHub reports no
  checks, verify the workflow trigger and base branch, update the trigger if
  needed, then re-check the same PR. Do not merge until checks for the current
  head are visible and green
- actionable review comments are fixed or explicitly resolved
- the PR is merged

## Pre-Push Checks

Before every push:

- Run `.claude/skills/pre-push-self-review/SKILL.md`.
- If tests or README test-count claims changed, run `.claude/skills/test-count-readme-sync/SKILL.md`.
- Confirm no secrets, debug output, or app-specific coupling entered the diff.

## v1.0 Stability Checklist

When touching public-facing code, also check:

- The class carries `@api` OR `@internal` in its docblock — never both. Classes that accept an `@internal` type in their public constructor are internal.
- Internal namespaces (`Persistence`, `Models`, `Queue`, `Jobs`, `Console`) are not part of the v1.0 surface. Route new extension points through `Padosoft\LaravelFlow\Contracts\*`.
- `tests/Contract/PublicApiContractTest.php` must be updated whenever an `@api` class, public method, or public constant is added or renamed. Removing one requires a major version bump and `docs/UPGRADE.md` update.
- `DashboardActionAuthorizer` default binding must remain `DenyAllAuthorizer`. `AllowAllAuthorizer` is opt-in for development only.
- Plain approval tokens never appear in storage, logs, audit, or webhook payloads. The dashboard authorizer accepts a token hash via `ApprovalTokenManager::hashToken($plainToken)`.
- Read DTOs return whatever is stored. Documentation must always reference the `laravel-flow.persistence.redaction.enabled` config gate when describing redaction behavior.
