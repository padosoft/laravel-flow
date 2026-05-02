# Claude Instructions For Laravel Flow

This file is the Claude-compatible entrypoint for the repository. It mirrors `AGENTS.md` and points agents to the durable restart files.

## Read First

1. `docs/PROGRESS.md`
2. `docs/ENTERPRISE_PLAN.md`
3. `docs/RULES.md`
4. `docs/LESSON.md`
5. `.claude/skills/laravel-flow-enterprise/SKILL.md`

## Non-Negotiable Rules

- Work through macro branches and subtask PRs.
- Request GitHub Copilot Code Review on every PR and wait for it.
- Merge only after local gates pass, reported CI checks are green, and actionable review comments are resolved.
- Update `docs/PROGRESS.md` during work and `docs/LESSON.md` when learning something reusable.
- For every new or improved feature, review README section `Comparison vs alternatives`; update it and research competitor behavior before making uncertain claims.
- Keep package core standalone-agnostic.
- Keep code compatible with the active Composer/CI matrix. Today that means Laravel 12/13; Laravel 13-only is the enterprise direction only after Macro Task 1 narrows Composer and CI.
- Dashboard work belongs in a companion app unless the plan is explicitly changed.

CI is configured for PRs targeting `main` and `task/**`, plus pushes to `main`. Do not add `task/**` to push triggers because macro and subtask branches both use the `task/` prefix. If a current PR has no checks because its base branch predates that workflow trigger, document the absence in `docs/PROGRESS.md` and verify CI on the next PR after the trigger lands.

## Skills

Use the repo-local skills when their trigger matches:

- `.claude/skills/laravel-flow-enterprise/SKILL.md`
- `.claude/skills/copilot-pr-review-loop/SKILL.md`
- `.claude/skills/pre-push-self-review/SKILL.md`
- `.claude/skills/test-count-readme-sync/SKILL.md`

Before pushing a branch that changed tests or README test-count claims, run the test-count sync skill.
