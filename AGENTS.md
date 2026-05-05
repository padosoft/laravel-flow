# Laravel Flow Agent Guide

This repository is the reusable package `padosoft/laravel-flow`.

If a session restarts with missing context, read these files first, in this order:

1. `docs/PROGRESS.md`
2. `docs/ENTERPRISE_PLAN.md`
3. `docs/RULES.md`
4. `docs/LESSON.md`
5. `.claude/skills/laravel-flow-enterprise/SKILL.md`

## Stable Baseline

- v0.1 includes the in-memory Flow engine core, fluent builder, facade, dry-run, reverse-order compensation, events, business-impact results, architecture tests, and the imported Padosoft Claude pack.
- `docs/PROGRESS.md` carries the human restart summary; verify live branch, PR, SHA, reviewer, and CI status with `git` and `gh`.
- Subtasks branch from the active macro branch and open PRs back into it.

## Operating Rules

- Current code must remain compatible with the Composer/CI matrix that is active on the branch. After Macro Task 1, that means Laravel 13 on PHP 8.3/8.4.
- The dashboard is a companion app, not UI embedded in this package.
- Keep the package core standalone-agnostic: no AskMyDocs, companion app, or product-specific symbols in `src/`.
- Update `docs/PROGRESS.md` after meaningful handoff points. For concurrent subtasks, keep detailed PR-specific CI/Copilot history in the PR and summarize only durable restart state.
- Update `docs/LESSON.md` after non-obvious discoveries, CI/Copilot review findings, local tool workarounds, or reusable package design decisions.
- Pass `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `docs/RULES.md`, `docs/LESSON.md`, and `.claude/skills/laravel-flow-enterprise/SKILL.md` into every background agent or future session.
- For every new or improved feature, check README section `Comparison vs alternatives` and update it with factual capability changes. If the package only reaches parity, say that accurately. Research competitors before changing competitor claims when unsure.
- Never expose secrets in logs, docs, UI, webhook payloads, audit payloads, or debug output.
- Do not implement code directly on a macro branch unless the user explicitly overrides the PR model.

## Branch And PR Loop

Macro branches:

- `task/agent-operating-system`
- `task/baseline-tooling-laravel13`
- `task/v02-persistence`
- `task/v02-queues-replay`
- `task/v03-approval-webhooks`
- `task/dashboard-contracts`
- `task/v10-stable-api-migrations`
- `task/release-docs-v1`

For each subtask:

1. Create a subtask branch from the current macro branch.
2. Implement the smallest coherent slice.
3. Run the relevant local gates.
4. Open a PR from the subtask branch into the macro branch.
5. Request GitHub Copilot Code Review.
6. Wait for reported CI checks and Copilot comments.
7. Fix all red reported CI and actionable Copilot comments.
8. Repeat until CI is green and review comments are resolved.
9. Merge the subtask PR into the macro branch.
10. When a macro branch is complete, open a macro PR into `main` and run the same loop.

Copilot review means GitHub Copilot Code Review, not a Codex review. If `gh pr edit <PR> --add-reviewer @copilot` fails because of GitHub CLI project scope issues or the `copilot` login does not resolve, use the GraphQL `requestReviewsByLogin` fallback documented in `.claude/skills/copilot-pr-review-loop/SKILL.md`.

CI is configured for PRs targeting `main` and `task/**`, plus pushes to `main`. Do not add `task/**` to push triggers: both macro and subtask branches use the `task/` prefix. If a PR reports no checks, verify the workflow trigger and base branch, update the trigger if needed, then re-check the same PR; do not merge until checks for the current head are visible and green.

## Local Gates

For this package, run:

```bash
composer validate --strict --no-check-publish
composer format:test
composer analyse
composer test
```

If the companion dashboard app is being changed, also run its PHP, Node, Vite, Vitest, and Playwright gates as documented in that app.

If a tool is unavailable, blocked, or remote CI/Copilot cannot be verified, do not fake completion. Record the exact blocker and next remote step in `docs/PROGRESS.md`.

## v1.0 Stability Rules

From v1.0 onward, classes carry an explicit visibility contract in their docblock. Treat these tags as load-bearing.

- Class-level `@api` means the class, its public method signatures, and its public constants are SemVer-covered. Breaking changes only happen on a major version bump and ship with `docs/UPGRADE.md` updated. The contract test suite `tests/Contract/` pins this surface; if you must change an `@api` class, update both the test and the upgrade guide in the same PR.
- Class-level `@internal` means the class is implementation detail. Do not type-hint, extend, mock, or reflect on `@internal` classes from host code. Internal classes can change in any release. Internal namespaces today: `Persistence`, `Models`, `Queue`, `Jobs`, `Console`.
- Never give a class both `@api` and `@internal`. If a class accepts an `@internal` type in a public constructor, the class itself is internal — drop the `@api` tag. The contradiction was caught during Macro 6 review and is now flagged by the contract test policy.
- New host-app extension points must surface through `Padosoft\LaravelFlow\Contracts\*` interfaces, not by exposing a `Persistence/*` class. Custom backends extend the contract; the package keeps Eloquent-backed defaults.
- The companion dashboard lives in a separate repo (`padosoft/padosoft-laravel-flow-dashboard`). Package code stays headless. The dashboard contract surface is `Padosoft\LaravelFlow\Dashboard\*`; the brief for an AI agent or human team building the companion app is at `docs/DASHBOARD_APP_SPEC.md`.
- `DashboardActionAuthorizer` is registered as `DenyAllAuthorizer` by default. Production deployments MUST bind their own implementation. `AllowAllAuthorizer` is opt-in for development only.
- Approval tokens are SHA-256 hashed at rest and never recoverable from `flow_approvals`. The dashboard authorizer takes a token hash, not the plain token; compute it via `ApprovalTokenManager::hashToken($plainToken)` before calling the authorizer.
- Payload redaction depends on `laravel-flow.persistence.redaction.enabled`. Read DTOs (`RunDetail`, `ApprovalSummary`) return whatever is stored; host apps that disable redaction must add their own sanitisation pass before rendering, never advertise an unconditional package-level guarantee.
