# Progress

## 2026-05-02 - Active Handoff

Known workstreams:

| Workstream | Branch / PR | Durable state |
| --- | --- | --- |
| Macro Task 0 - durable agent operating system | `task/agent-docs-bootstrap` -> `task/agent-operating-system`, PR #4 | In review loop for the restart docs subtask. |

Concurrent subtasks must add rows here instead of replacing existing workstreams.
Verify live head, reviewer, mergeability, and CI with `git status --short --branch`, `gh pr view <PR> --json headRefOid,mergeable,statusCheckRollup,reviewDecision,reviews`, and `gh api repos/padosoft/laravel-flow/pulls/<PR>/requested_reviewers`.
- This file is a durable handoff summary. Detailed per-poll CI/Copilot iteration history belongs in PR #4, not in this shared file.

Completed in Macro Task 0:

- Created `task/agent-operating-system` from `origin/main` and subtask branch `task/agent-docs-bootstrap`.
- Added durable restart files: `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, `docs/LESSON.md`, `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `.github/copilot-instructions.md`, `.claude/skills/laravel-flow-enterprise/SKILL.md`, and `.claude/rules/rule-laravel-flow-enterprise.md`.
- Imported/adapted useful Padosoft Claude pack guidance from the reference project without copying app-specific implementation rules.
- Updated CI so PRs targeting `main` or `task/**` run the matrix; push-trigger CI remains limited to `main` to avoid duplicate subtask runs.
- Recorded the durable rule that README section `Comparison vs alternatives` must be reviewed for every new or materially improved feature, with competitor research when claims are uncertain.
- Aligned README, CONTRIBUTING, PR template, Copilot instructions, repo rules, and repo skills around the macro/subtask workflow, Laravel 12/13 compatibility until Macro Task 1, companion-dashboard scope, and mandatory Copilot review.

Validation summary:

- Local gates were run after the latest edits in this subtask:
  - `composer validate --strict --no-check-publish`
  - `vendor/bin/pint --test`
  - `vendor/bin/phpstan analyse --no-progress`
  - `vendor/bin/phpunit --testsuite Unit` => 32 tests, 97 assertions
  - `vendor/bin/phpunit --testsuite Architecture` => 2 tests, 7 assertions
- Remote CI and Copilot review state are intentionally not mirrored here per commit. Verify the current PR state with `gh pr checks <PR>` and the review-thread GraphQL query documented in `.claude/skills/copilot-pr-review-loop/SKILL.md`.

Restart action:

- Continue PR #4 from live GitHub state. Do not merge until local gates are green for the latest head, CI is green for the latest head, Copilot review has completed for the latest head, and no actionable non-outdated Copilot threads remain.
