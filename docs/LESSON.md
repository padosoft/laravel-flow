# Lessons

## 2026-05-02

- v0.1 is no longer a no-op scaffold. It includes the in-memory Flow engine core, facade, dry-run, compensation, events, business-impact results, README expansion, and architecture tests.
- The enterprise direction chosen by the user is Laravel 13-only, but current `composer.json` and CI still test Laravel 12/13. Narrowing compatibility belongs to Macro Task 1, not the restart-docs subtask.
- The dashboard direction chosen by the user is companion app, not package-embedded UI.
- The imported `.claude` pack already contains useful PR-loop and pre-push skills. Do not duplicate the full content; link to it and add laravel-flow-specific rules.
- Copilot review must be GitHub Copilot Code Review. If normal reviewer assignment fails because GitHub CLI lacks project scopes, use the GraphQL `requestReviewsByLogin` fallback from `.claude/skills/copilot-pr-review-loop/SKILL.md`.
- For this package repo, Vite/Vitest/Playwright are not local gates unless the companion dashboard app is touched.
- When tests are added or assertion counts change, run the `test-count-readme-sync` skill before pushing so README and PR descriptions do not drift.
