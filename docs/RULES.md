# Laravel Flow Rules

## Source Of Truth

- Enterprise plan: `docs/ENTERPRISE_PLAN.md`.
- Work handoff summary: `docs/PROGRESS.md`.
- Reusable findings: `docs/LESSON.md`.
- Agent entrypoints: `AGENTS.md` and `CLAUDE.md`.
- Repo-local skill: `.claude/skills/laravel-flow-enterprise/SKILL.md`.

## Product Direction

- Current implementation must remain compatible with the active Composer/CI matrix.
- After Macro Task 1, the active matrix is Laravel 13 and PHP `^8.3`, with CI hard gates on PHP 8.3 and 8.4.
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

## Preflight Rules

Before writing code for a behavioral change or Copilot review fix, run a short local design review and make the patch plan explicit:

- Public contract and backward compatibility: new methods, bindings, optional extension points, default behavior, and upgrade paths.
- Edge cases and branch coverage: invalid input, stale state, repeated/idempotent calls, drift, missing migrations, and failure paths.
- Diagnostics and commands: CLI/API failures should be actionable and avoid raw framework internals where package-level guidance is possible.
- Docs and README: update shipped capability wording, `Comparison vs alternatives`, config examples, and test/assertion counts when behavior changes.
- Retention and concurrency: locks, transactions, pruning/deletion behavior, retry races, and duplicate delivery/decision paths.
- Tests: add explicit tests for each meaningful behavior branch instead of relying on Copilot to identify missing coverage.

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
composer format:test
composer analyse
composer test
```

For persistence, queue, commands, approval gates, and webhooks, add Testbench feature tests with SQLite.

For companion dashboard app/repo changes, add these gates in the companion app repository. Package-only dashboard contracts stay on the package gates above.

```text
vendor/bin/phpunit
npm run test
npm run build
npm run e2e
```

Playwright is required only when UI/UX changes are in scope.

## Documentation Rules

- Update `docs/PROGRESS.md` after meaningful handoff points. For concurrent subtasks, keep detailed PR-specific Copilot/CI history in the PR and summarize only durable restart state in the shared progress file.
- Update `docs/LESSON.md` only after reusable discoveries from Copilot comments, CI failures, local tooling, or design decisions.
- Keep entries dated with `YYYY-MM-DD`.
- README must never promise unimplemented behavior as available.
- README test/assertion counts must match the actual PHPUnit output.
- When adding or improving any package feature, review README section `Comparison vs alternatives` and update it so the table reflects the new or improved capability.
- Every `Comparison vs alternatives` capability cell should keep the explicit status-prefix format: `✅ YES - ...`, `⚠️ PARTIAL - ...`, or `❌ NO - ...`.
- If a competitor capability in that section is uncertain, research the referenced package/product before updating the comparison.

## PR Rules

- Macro branch per macro task.
- Subtask branch per coherent implementation slice.
- Subtask PR targets the macro branch.
- Macro PR targets `main`.
- Request Copilot Code Review for every PR.
- CI is expected for PRs targeting `main` and `task/**`, plus pushes to `main`.
- Do not add `task/**` to push triggers; the branch naming scheme uses `task/` for both macro branches and subtask branches.
- Merge only after local gates, Copilot review, and reported CI checks are clean.
- If a PR reports no checks, verify the workflow trigger and base branch, update the trigger if needed, then re-check the same PR. Do not merge until checks for the current head are visible and green.
- If GitHub access is unavailable, record the exact blocked remote step in `docs/PROGRESS.md`.

## v1.0 Stability Rules

- Class-level `@api` and `@internal` docblock tags are load-bearing from v1.0. SemVer applies to `@api`; `@internal` may change in any release. Never combine the two on a single class.
- Internal namespaces today: `Padosoft\LaravelFlow\Persistence\*`, `Padosoft\LaravelFlow\Models\*`, `Padosoft\LaravelFlow\Queue\*`, `Padosoft\LaravelFlow\Jobs\*`, `Padosoft\LaravelFlow\Console\*`. Host apps must consume only the matching public contracts.
- The `tests/Contract/` testsuite pins class names, public methods, and constants for the v1.0 `@api` surface. Update it in the same PR when intentionally evolving the public API.
- Companion dashboard scope: separate repo (`padosoft/padosoft-laravel-flow-dashboard`). Package itself never ships UI. Spec: `docs/DASHBOARD_APP_SPEC.md`.
- `DashboardActionAuthorizer` ships as deny-by-default (`DenyAllAuthorizer`). Production deployments must override; `AllowAllAuthorizer` is the explicit dev opt-in.
- Approval tokens are SHA-256 hashed at rest. Plain tokens are returned only on the immediate `FlowRun` at issuance time. The dashboard authorizer takes a token hash via `ApprovalTokenManager::hashToken($plainToken)`.
- Read DTOs (`RunDetail`, `ApprovalSummary`) return whatever is stored. The package only redacts JSON payloads when `laravel-flow.persistence.redaction.enabled` is true. Documentation must always reference that gate when describing redaction behavior.
