# Rule: Flow 2.0 Program Workflow (MANDATORY)

Scope: `padosoft/laravel-flow`, `padosoft/laravel-flow-connect`, `padosoft/laravel-flow-ai`, `laravel-flow-admin` — the whole Laravel Flow 2.0 program.

Program source of truth:
- Spec: `docs/superpowers/specs/2026-07-07-flow-v2-super-package-design.md`
- Master plan (gates + macro A→G): `docs/superpowers/plans/2026-07-07-flow-v2-program-master-plan.md`
- Per-macro detailed plans: `docs/superpowers/plans/2026-07-07-macro-*.md` (written just-in-time at each macro gate)
- Studio UI design brief (handed to Claude Design): `docs/design/2026-07-07-flow-studio-ui-design-brief.md`

Macro order: **A → B → C → D → F(core, no UI) → E (Studio UI, penultimate — waits for the Claude Design template provided by the user) → G (release + knowhow)**.

## Definition of Ready (every task/subtask)

Before implementation, a task MUST declare:
1. A precise objective (one sentence, verifiable).
2. Implementation details (files, interfaces, signatures).
3. **Guardrails**: PHPUnit tests always; Vitest tests when JS is touched; **Playwright scenarios covering EVERY UI/UX interaction introduced** when UI is touched (code-only tasks are exempt from Playwright).

## Definition of Done (the loop — in this exact order)

1. **All local tests green**: `composer quality` (+ `npm run test`, `npm run build`, `npm run e2e` when frontend touched).
2. **Local Copilot CLI review loop** on the full branch diff vs `origin/main` until zero actionable findings — skill `local-copilot-review`.
3. Push; open PR toward the working branch (subtask → macro branch; macro → `main`).
4. Add Copilot as reviewer and **verify the review request is registered** (GraphQL fallback in `copilot-pr-review-loop` skill if needed).
5. Wait for BOTH: all CI checks green on the current head AND Copilot review comments.
6. All green and all actionable comments resolved → merge. Otherwise fix, re-run steps 1–2, push, re-request review, GOTO 5.
7. ONLY then the task is done — move to the next task. Never stop after a push; never fake the loop: if a remote step is unavailable, record the exact blocker and next remote step in `docs/PROGRESS.md`.

## Session & knowledge discipline

- `docs/PROGRESS.md`: update at every meaningful step with crash-recovery quality (current branch, PR number, gates run with counts, exact next step). Dated `YYYY-MM-DD` sections, newest first.
- `docs/LESSON.md`: update on every non-obvious discovery — including lessons extracted from Copilot review comments (local and PR-level).
- **Every subagent prompt MUST include or instruct reading `docs/LESSON.md`** plus the relevant plan file. Every new/resumed session reads `docs/LESSON.md` before writing code.

## Environment

- Laravel 13.x (latest), PHP `^8.3` (raise a package floor to `^8.4` only with a documented dependency reason).
- Local test runtime on this machine: **Herd PHP 8.5** (`%USERPROFILE%\.config\herd\bin\php85.bat`); composer via the Herd shim (`composer.bat`). Never XAMPP PHP. Note: plain `bash` may not have `composer` on PATH — use PowerShell or call the Herd shim explicitly.
- CI matrix stays PHP 8.3 / 8.4 / 8.5.

## Completion duties (per package, before its release)

- README at wow level (same standard as `padosoft/laravel-flow`), `docs/` updated, `docs-site/` docmd updated (`npm run check` && `npm run build`), README "Comparison vs alternatives" only with fresh competitor research.
- **Final program task (mandatory)**: re-read `docs/LESSON.md` end-to-end and fold every reusable learning into the rules, skills, and `AGENTS.md`/`CLAUDE.md` of each repo touched. Then tag and publish the GitHub release.

## Program lessons (folded from LESSON.md — Macro E + Macro G, mandatory reading)

Durable, cross-cutting learnings from delivering Macro E (Studio UI + working mutations) and the Macro G follow-ups. Apply these on every future macro/subtask:

- **The macro-gate full-branch review is where cross-subtask consistency gaps surface** — per-subtask PR reviews are blind to siblings. It earned its keep twice: (1) the E-PR6 core-seam full-diff review caught bugs from earlier-merged subtasks that only appeared together; (2) Macro E's gate review caught `StudioController::dryRun()` as the ONE authoring endpoint left ungated + unthrottled while `editGraph`/`storeDraft`/`publish`/`ai-build` were all `edit_definition`-gated. At every gate, list EVERY endpoint of one class (e.g. authoring mutations) side by side and verify identical auth + throttle posture. **"The output has no secrets" does NOT justify leaving a compute-bearing, arbitrary-input endpoint ungated** — gate + throttle it like its siblings.
- **UI eligibility flags MUST mirror the route/controller constraints exactly.** A button whose visibility flag is looser than the endpoint it posts to will render and then 404 (or be a guaranteed no-op). Examples: `OutboxRow::canRedeliver` must match the seam's real precondition (`status==='failed'`) AND the route (`[0-9]{1,18}` + canonical-int round-trip); `ApprovalCard::canDecide` must match the `{tokenHash}` route regex (`[A-Fa-f0-9]{64}`). Derive the flag from the seam's actual precondition, not a pre-existing looser flag.
- **A new core `@api` field is invisible downstream until every hop forwards it** when an anti-corruption layer re-maps by hand: core DTO → your DTO → both adapters' mappers → view-model → blade. Grep the whole chain and add a read-model test asserting the field is populated end-to-end (the approval `tokenHash` seam was dead-on-arrival until this was done). **An additive `@api` interface method requires, in the SAME PR:** the impl, EVERY test double updated (there were FOUR `ApprovalRepository` doubles; some implement `X, ApprovalRepository` so a naive `implements ApprovalRepository` grep misses them — grep a method name like `consumePending` instead), the `tests/Contract/` pin (if the class has a method list), and a `docs/UPGRADE.md` additive note.
- **A strict `array{...}` phpdoc makes a defensive runtime guard PHPStan-dead** (`booleanOr.alwaysFalse` / offset-always-exists). You can't have both the strict shape AND a runtime guard for it — either loosen the declared type to `array<string,mixed>` so the guard is reachable, or keep the shape and rely on static analysis. Never reach for `@phpstan-ignore` (banned).
- **CI E2E single-browser flake = re-run the shard, not code.** The admin E2E matrix flakes a DIFFERENT single browser on most pushes with a timeout-then-cascade signature (one test times out ~6s, then every later test — including unrelated specs — fails fast): that's the single-threaded `testbench serve` stalling, NOT a code bug (all three browsers pass locally). `gh run rerun <id> --failed` re-runs only the flaked shard; never change code for it. Confirm by running the full `npm run test:e2e` locally across all three browsers.
- **Persistence-outage vs state-conflict must be distinguishable at the API boundary.** Core raises a distinct `PersistenceUnavailableException` (subtype of `FlowExecutionException`, and parent of `ApprovalPersistenceException`) from `cancel`/`replay`/`redeliver`/approvals so a companion dashboard maps infra outages → 503 (retryable) and state conflicts → 409. Catch the subtype BEFORE its parent.
- **`dev-main` + local `path` repositories are the program's PRE-RELEASE consuming posture** (the admin/ai consume core's unreleased v2.0 `@api` off `main`). They are intentional in dev/CI and MUST be converted at the release macro: `dev-main` → tagged `^2.0`, and REMOVE the `path` repositories (they break a real consumer's `composer install`). Do not "fix" them mid-program.
- **A reserved-but-unenforced contract method is a valid design, not dead code** — but make the contract honest. In `laravel-flow-admin`, the methods `Padosoft\LaravelFlowAdmin\Contracts\ActionAuthorizer::canViewRuns()`, `::canViewRunDetail()`, and `::canViewKpis()` (NOT core's `Dashboard\Authorization\DashboardActionAuthorizer` — a different class) are declared but wired into no controller BY DESIGN: enforcing views under the shipped deny-all authorizer would break the documented "browse on day 1" value prop. Document such methods as "RESERVED — not yet enforced" rather than wiring them (breaks the default UX) or removing them (breaking interface change).
