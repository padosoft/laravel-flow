# Rule: Laravel Flow Enterprise Roadmap

- Read `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `docs/RULES.md`, and `docs/LESSON.md` before changing code.
- Use macro branches and subtask PRs exactly as documented in `AGENTS.md`.
- Keep the package core standalone-agnostic and headless.
- Keep implementation compatible with the active Composer/CI matrix; Laravel 13-only becomes actionable only when Macro Task 1 narrows Composer and CI.
- Keep dashboard implementation in a companion app unless the plan is explicitly changed.
- For every new or materially improved feature, update README section `Comparison vs alternatives`; research competitor behavior before changing uncertain claims.
- Update progress and lessons during work, not only at handoff.
- Every PR requires local gates, GitHub Copilot Code Review, and CI when the repository reports checks for that PR.
- Subtask PR CI is expected for PRs targeting `task/**`; do not add `task/**` to push triggers because macro and subtask branches share the `task/` prefix.
- If a PR reports no checks, verify the workflow trigger and base branch, update the trigger if needed, then re-check the same PR. Do not merge until checks for the current head are visible and green.
