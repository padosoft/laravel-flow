# Laravel Flow Rules

## Source Of Truth

- Enterprise plan: `docs/ENTERPRISE_PLAN.md`.
- Work status: `docs/PROGRESS.md`.
- Reusable findings: `docs/LESSON.md`.
- Agent entrypoints: `AGENTS.md` and `CLAUDE.md`.
- Repo-local skill: `.claude/skills/laravel-flow-enterprise/SKILL.md`.

## Product Direction

- Target enterprise baseline: Laravel 13-only, PHP `^8.3`.
- Current v0.1 baseline still advertises Laravel 12/13 support; narrowing compatibility is Macro Task 1.
- Core package stays headless and standalone-agnostic.
- Dashboard is a separate companion app.
- Public APIs must be explicit, documented, and pinned by tests before v1.0.

## Implementation Defaults

- Prefer immutable DTOs, contracts, enums/constants, and small focused services.
- Keep Laravel integration at the edges: provider, facade, events, config, migrations, commands.
- Avoid hidden side effects in dry-run paths.
- Compensation must be observable and auditable.
- Persisted runs and audit rows must never silently lose failure context.
- Queue/replay behavior must be deterministic enough to debug from stored run records.

## Security Rules

- Do not store approval tokens in clear text.
- Do not expose raw secrets in audit, webhook, UI, exception, log, debug, or README examples.
- Webhook payloads must be signed and redacted.
- Operator-facing errors must be sanitized.
- Any future dashboard action that mutates runs requires middleware/policy hooks and confirmation UX.

## Testing Rules

Every package subtask should run the relevant subset:

```text
composer validate --strict --no-check-publish
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
```

For persistence, queue, commands, approval gates, and webhooks, add Testbench feature tests with SQLite.

For companion dashboard UI, add:

```text
vendor/bin/phpunit
npm run test
npm run build
npm run e2e
```

Playwright is required only when UI/UX changes are in scope.

## Documentation Rules

- Update `docs/PROGRESS.md` after meaningful work.
- Update `docs/LESSON.md` after discoveries, Copilot comments, CI failures, or reusable design decisions.
- Keep entries dated with `YYYY-MM-DD`.
- README must never promise unimplemented behavior as available.
- README test/assertion counts must match the actual PHPUnit output.

## PR Rules

- Macro branch per macro task.
- Subtask branch per coherent implementation slice.
- Subtask PR targets the macro branch.
- Macro PR targets `main`.
- Request Copilot Code Review for every PR.
- Merge only after local gates, CI, and Copilot review are clean.
- If GitHub access is unavailable, record the exact blocked remote step in `docs/PROGRESS.md`.
