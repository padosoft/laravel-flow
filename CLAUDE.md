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
- Merge only after local gates pass, CI is green, and actionable review comments are resolved.
- Update `docs/PROGRESS.md` during work and `docs/LESSON.md` when learning something reusable.
- Keep package core standalone-agnostic.
- Treat Laravel 13-only as the enterprise direction even while current v0.1 composer metadata still supports Laravel 12/13.
- Dashboard work belongs in a companion app unless the plan is explicitly changed.

## Skills

Use the repo-local skills when their trigger matches:

- `.claude/skills/laravel-flow-enterprise/SKILL.md`
- `.claude/skills/copilot-pr-review-loop/SKILL.md`
- `.claude/skills/pre-push-self-review/SKILL.md`
- `.claude/skills/test-count-readme-sync/SKILL.md`

Before pushing a branch that changed tests or README test-count claims, run the test-count sync skill.
