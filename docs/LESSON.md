# Lessons

## 2026-05-02

- v0.1 is no longer a no-op scaffold. It includes the in-memory Flow engine core, facade, dry-run, compensation, events, business-impact results, README expansion, and architecture tests.
- The enterprise direction chosen by the user is Laravel 13-only, but current `composer.json` and CI still test Laravel 12/13. Narrowing compatibility belongs to Macro Task 1, not the restart-docs subtask.
- The dashboard direction chosen by the user is companion app, not package-embedded UI.
- The imported `.claude` pack already contains useful PR-loop and pre-push skills. Do not duplicate the full content; link to it and add laravel-flow-specific rules.
- For this package repo, Vite/Vitest/Playwright are not local gates unless the companion dashboard app is touched.
- When tests are added or assertion counts change, run the `test-count-readme-sync` skill before pushing so README and PR descriptions do not drift.
- Until Macro Task 1 narrows Composer and CI, all code must stay Laravel 12/13-compatible even though Laravel 13-only is the enterprise target.
- Do not use `task/**` as a push trigger. Macro and subtask branches both use the `task/` prefix, so `task/**` belongs only in PR base triggers unless branch naming changes.
- README and CONTRIBUTING are part of the durable workflow contract. When AGENTS/RULES change branching or CI behavior, update public contributor docs in the same PR.
- Keep companion-dashboard scope consistent everywhere it appears, including README intro copy and roadmap rows; changing only one mention leaves the public docs ambiguous.
- Restart/subagent context must include the repo-local skill `.claude/skills/laravel-flow-enterprise/SKILL.md`, not only the four durable docs, because the skill carries mandatory PR-loop and pre-push behavior.
- Copilot review threads can remain non-outdated after a fix when the same line still exists. Verify current file contents against the comment before resolving a thread as already addressed.
- Durable plans should reference release tags and capabilities, not exact moving branch SHAs. Verify live branch, PR, and SHA state with `git` and `gh`; keep `docs/PROGRESS.md` as a human handoff summary.
- Repository-wide PR templates must work for both enterprise roadmap PRs and normal community PRs.
- Final lesson fold-back must include `.github/copilot-instructions.md` so Copilot's durable guidance stays aligned with AGENTS, CLAUDE, rules, and skills.
- Repo-local rule files must include the same mandatory reading list as AGENTS/CLAUDE when they are part of the durable instruction surface.
- Dashboard app/repo PR gates must include the companion app's PHPUnit suite as well as Vitest/Vite/Playwright checks when dashboard code changes.
- Final lesson fold-back must include repo rule files as well as AGENTS, CLAUDE, `docs/RULES.md`, Copilot instructions, and skills.
- PR templates should include an explicit GitHub Copilot Code Review checkpoint when the repository makes that review mandatory.
- Keep detailed PR-specific Copilot/CI history in the PR; write only durable handoff state to `docs/PROGRESS.md` and reusable takeaways to `docs/LESSON.md`.
- Dashboard gates belong to the companion app/repo unless the package PR also changes that app; package-only dashboard contracts use package gates.
- Final lesson fold-back must include `.github/PULL_REQUEST_TEMPLATE.md` because it is part of the durable workflow surface.
- `docs/PROGRESS.md` should be a handoff summary, not an append-only remote poll log for every concurrent subtask. Keep detailed PR-specific CI/Copilot iteration history in the PR to avoid shared-file conflicts.
- `docs/PROGRESS.md` should track concurrent subtasks as separate workstream rows and should not mirror commit-specific CI/Copilot status.
- `docs/PROGRESS.md` must be safe to merge into `main`; avoid in-flight PR numbers or branch arrows unless they are clearly historical or live state is verified through `gh`.
- Repo-local laravel-flow guidance must explicitly override imported shared Laravel 13 defaults until Macro Task 1 narrows Composer and CI.
- README comparison updates must stay factual. If a feature only reaches parity with a competitor, document parity rather than implying an advantage.
