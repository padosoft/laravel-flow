# Rule: Laravel Flow Enterprise Roadmap

- Read `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `docs/RULES.md`, and `docs/LESSON.md` before changing code.
- Use macro branches and subtask PRs exactly as documented in `AGENTS.md`.
- Keep the package core standalone-agnostic and headless.
- Treat Laravel 13-only as the enterprise target, even if current compatibility metadata has not yet been narrowed.
- Keep dashboard implementation in a companion app unless the plan is explicitly changed.
- Update progress and lessons during work, not only at handoff.
- Every PR requires local gates, CI, and GitHub Copilot Code Review before merge.
