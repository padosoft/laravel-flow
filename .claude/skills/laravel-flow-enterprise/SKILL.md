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

- Enterprise target: Laravel 13-only, PHP `^8.3`.
- Current v0.1 baseline still supports Laravel 12/13 until Macro Task 1 narrows it.
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

Update `docs/LESSON.md` after:

- Copilot comments
- CI failures with reusable cause
- local environment discoveries
- API/design decisions that future agents must preserve
- security, redaction, queue, replay, or migration edge cases

## Quality Gates

For package-only changes:

```bash
composer validate --strict --no-check-publish
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
```

For companion dashboard changes, add the companion app's PHP, Node, Vite, Vitest, and Playwright gates.

## PR Loop

Use `.claude/skills/copilot-pr-review-loop/SKILL.md` for the mandatory remote loop.

Do not mark a task done until:

- local gates are green
- PR exists against the correct target branch
- Copilot Code Review was requested and completed
- CI is green
- actionable review comments are fixed or explicitly resolved
- the PR is merged

## Pre-Push Checks

Before every push:

- Run `.claude/skills/pre-push-self-review/SKILL.md`.
- If tests or README test-count claims changed, run `.claude/skills/test-count-readme-sync/SKILL.md`.
- Confirm no secrets, debug output, or app-specific coupling entered the diff.
