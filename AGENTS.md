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
- Current branch, PR, SHA, reviewer, and CI status are intentionally kept only in `docs/PROGRESS.md` to avoid drift.
- Subtasks branch from the active macro branch and open PRs back into it.

## Operating Rules

- Treat the enterprise target as Laravel 13-only and PHP `^8.3`; composer still supports Laravel 12/13 until the baseline tooling macro narrows it.
- The dashboard is a companion app, not UI embedded in this package.
- Keep the package core standalone-agnostic: no AskMyDocs, companion app, or product-specific symbols in `src/`.
- Update `docs/PROGRESS.md` after meaningful work.
- Update `docs/LESSON.md` after non-obvious discoveries, CI/Copilot review findings, local tool workarounds, or reusable package design decisions.
- Pass `docs/LESSON.md` and `docs/PROGRESS.md` into every background agent or future session.
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

CI is configured for PRs targeting `main` and `task/**`. If a PR targets a base branch that predates the workflow trigger and GitHub reports no checks, record that exact state in `docs/PROGRESS.md` and require the next PR on the updated base branch to prove CI.

## Local Gates

For this package, run:

```bash
composer validate --strict --no-check-publish
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
```

If the companion dashboard app is being changed, also run its PHP, Node, Vite, Vitest, and Playwright gates as documented in that app.

If a tool is unavailable, blocked, or remote CI/Copilot cannot be verified, do not fake completion. Record the exact blocker and next remote step in `docs/PROGRESS.md`.
