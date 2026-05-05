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
- Keep code compatible with the active Composer/CI matrix. After Macro Task 1, that means Laravel 13 on PHP 8.3/8.4.
- Dashboard work belongs in a companion app unless the plan is explicitly changed.

CI is configured for PRs targeting `main` and `task/**`, plus pushes to `main`. Do not add `task/**` to push triggers because macro and subtask branches both use the `task/` prefix. If a PR reports no checks, verify the workflow trigger and base branch, update the trigger if needed, then re-check the same PR; do not merge until checks for the current head are visible and green.

## Skills

Use the repo-local skills when their trigger matches:

- `.claude/skills/laravel-flow-enterprise/SKILL.md`
- `.claude/skills/copilot-pr-review-loop/SKILL.md`
- `.claude/skills/pre-push-self-review/SKILL.md`
- `.claude/skills/test-count-readme-sync/SKILL.md`

Before pushing a branch that changed tests or README test-count claims, run the test-count sync skill.

## v1.0 Stability Rules

- Public surface is annotated `@api`; internal namespaces are annotated `@internal`. The `tests/Contract/` testsuite pins the v1.0 surface — keep it in sync with any `@api` change.
- Never mix `@api` and `@internal` on the same class. Classes whose constructors accept internal types are internal.
- Companion dashboard is a separate repo (`padosoft/padosoft-laravel-flow-dashboard`); package stays headless. Spec: `docs/DASHBOARD_APP_SPEC.md`.
- `DashboardActionAuthorizer` default binding is `DenyAllAuthorizer`. Production deployments must bind a real authorizer; `AllowAllAuthorizer` is dev-only.
- Plain approval tokens are never recoverable from storage. The dashboard authorizer takes a token hash via `ApprovalTokenManager::hashToken($plainToken)`.
- Read DTOs return whatever is stored; advertised redaction guarantees must always reference the `laravel-flow.persistence.redaction.enabled` config gate.
- See `docs/UPGRADE.md` for the full SemVer policy and upgrade guidance.
