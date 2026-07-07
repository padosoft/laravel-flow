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
