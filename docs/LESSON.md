# Lessons

## 2026-05-02

- v0.1 is no longer a no-op scaffold. It includes the in-memory Flow engine core, facade, dry-run, compensation, events, business-impact results, README expansion, and architecture tests.
- The enterprise direction chosen by the user is Laravel 13-only. Macro Task 0 intentionally left `composer.json` and CI on Laravel 12/13; Macro Task 1 is the branch that narrows compatibility.
- The dashboard direction chosen by the user is companion app, not package-embedded UI.
- The imported `.claude` pack already contains useful PR-loop and pre-push skills. Do not duplicate the full content; link to it and add laravel-flow-specific rules.
- For this package repo, Vite/Vitest/Playwright are not local gates unless the companion dashboard app is touched.
- When tests are added or assertion counts change, run the `test-count-readme-sync` skill before pushing so README and PR descriptions do not drift.
- After Macro Task 1, the active implementation baseline is Laravel 13 with PHP 8.3 and 8.4 as hard CI gates. PHP 8.5 remains outside hard CI until dependency support is reliable.
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
- Keep `docs/PROGRESS.md` rows free of Copilot comment details and exact local tool versions; detailed iteration history belongs in the PR body/comments, while PROGRESS keeps durable restart state only.
- Completed `docs/PROGRESS.md` workstream rows should describe the durable post-merge state on `main`, not the temporary macro/subtask branch that produced it.
- Repo-local laravel-flow guidance must remain the authority over imported shared defaults; after Macro Task 1 it defines the Laravel 13-only baseline and Composer-script gates.
- Composer scripts are the canonical package gates after Macro Task 1: `composer validate --strict --no-check-publish`, `composer format:test`, `composer analyse`, and `composer test`.
- Package repos intentionally ignore `composer.lock`; CI installs with `composer update`, so do not stage a local lockfile unless the project policy changes.
- In package repos without a tracked lockfile, avoid overly specific patch-minimum dev constraints unless there is a documented compatibility reason; prefer broader major/minor ranges and let transitive constraints enforce required patch floors.
- With Testbench 11, the lock can resolve `laravel/framework` 13.x, which replaces the individual `illuminate/*` packages. Use `composer show laravel/framework --locked` to verify the effective Laravel version when `composer show illuminate/support --locked` is absent.
- Public README examples should avoid Laravel dump-and-die or other debug helpers; use normal variable assignment or assertions so docs do not teach debug output patterns.
- When `composer validate --strict --no-check-publish` is a hard CI/PR gate, list it explicitly in contributor quick starts and PR expectation checklists, not only in CI or PR templates.
- README comparison updates must stay factual. If a feature only reaches parity with a competitor, document parity rather than implying an advantage.
