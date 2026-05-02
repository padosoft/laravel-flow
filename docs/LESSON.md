# Lessons

## 2026-05-02

- After fetch, `origin/main` advanced to `208a9d1` and is tagged `v0.1.0`; always branch new enterprise work from `origin/main`, not the stale local `main`.
- v0.1 is no longer a no-op scaffold. It includes the in-memory Flow engine core, facade, dry-run, compensation, events, business-impact results, README expansion, and architecture tests.
- The enterprise direction chosen by the user is Laravel 13-only, but current `composer.json` and CI still test Laravel 12/13. Narrowing compatibility belongs to Macro Task 1, not the restart-docs subtask.
- The dashboard direction chosen by the user is companion app, not package-embedded UI.
- The package currently has no `docs/` directory on `origin/main`; restart durability requires adding `docs/ENTERPRISE_PLAN.md`, `docs/PROGRESS.md`, `docs/RULES.md`, and this file before further implementation.
- The imported `.claude` pack already contains useful PR-loop and pre-push skills. Do not duplicate the full content; link to it and add laravel-flow-specific rules.
- `composer validate --strict --no-check-publish` can fail on the v0.1 baseline because `composer.lock` has a stale content hash after composer metadata changes; `composer update --lock --no-interaction --no-progress` rewrites the lock without changing package versions and makes validation pass.
- Copilot review must be GitHub Copilot Code Review. If normal reviewer assignment fails because GitHub CLI lacks project scopes, use the GraphQL `requestReviewsByLogin` fallback from `.claude/skills/copilot-pr-review-loop/SKILL.md`.
- For this package repo, Vite/Vitest/Playwright are not local gates unless the companion dashboard app is touched.
- When tests are added or assertion counts change, run the `test-count-readme-sync` skill before pushing so README and PR descriptions do not drift.
