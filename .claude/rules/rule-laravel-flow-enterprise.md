# Rule: Laravel Flow Enterprise Roadmap

- Read `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `docs/RULES.md`, and `docs/LESSON.md` before changing code.
- Use macro branches and subtask PRs exactly as documented in `AGENTS.md`.
- Keep the package core standalone-agnostic and headless.
- Treat Laravel 13-only as the enterprise target, even if current compatibility metadata has not yet been narrowed.
- Keep dashboard implementation in a companion app unless the plan is explicitly changed.
- Update progress and lessons during work, not only at handoff.
- Every PR requires local gates, GitHub Copilot Code Review, and CI when the repository reports checks for that PR.
- Subtask PR CI is expected on macro branches matching `task/**`; if a base branch predates that workflow trigger, document the missing checks in `docs/PROGRESS.md` and verify CI on the next PR after the trigger is merged.
