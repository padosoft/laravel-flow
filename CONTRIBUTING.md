# Contributing to laravel-flow

Thank you for your interest in contributing. The package is a community open-source project under the [Apache-2.0 license](LICENSE) and follows the Padosoft contribution conventions.

## Quick start for contributors

```bash
git clone https://github.com/padosoft/laravel-flow.git
cd laravel-flow
composer install
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The default `phpunit` invocation runs only the offline `Unit` + `Architecture` testsuites — it never makes a network call. The `Live` testsuite is opt-in (set `LARAVEL_FLOW_LIVE=1`).

## Branching model

Community PRs target `main` directly. Enterprise roadmap work may use the macro/subtask PR loop documented in `AGENTS.md`, where subtask PRs target a macro branch and macro PRs target `main`. Open one PR per cohesive change.

Branch-name conventions:

- `feature/<topic>` — new capability, new step type, new event, new strategy.
- `fix/<topic>` — bug fix on shipped behaviour.
- `docs/<topic>` — README, CHANGELOG, or `docs/` updates.
- `chore/<topic>` — dependency bumps, CI tweaks, repo hygiene.
- `task/<topic>` — enterprise macro or subtask branch used by the documented agent workflow.

## Pull request expectations

- The default `Unit` + `Architecture` suites must stay green on the full PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 matrix.
- Any new step handler / compensator implements the appropriate interface (`FlowStepHandler` / `FlowCompensator`) — closures are not accepted.
- Any change to the event surface adds a corresponding test in `FlowEventEmissionTest`.
- The standalone-agnostic invariant (`tests/Architecture/StandaloneAgnosticTest`) must keep passing — no AskMyDocs / sister-package symbols may appear under `src/`.
- `vendor/bin/pint --test` must pass with no diffs.
- `vendor/bin/phpstan analyse` must report zero errors at level 6.
- GitHub Copilot Code Review must run on every PR. Authors with permission should request it; otherwise a maintainer will request it before merge. Actionable comments must be addressed.
- The README's "Features at a glance" bullet list stays in sync with what the code does — add a bullet when you ship a feature, remove one when you remove it.
- The README's "Comparison vs alternatives" section stays in sync with new or materially improved features; research competitor behavior before changing uncertain comparison claims.

## Commits

We follow the conventional `<type>(scope): subject` shape used across all `padosoft/*` repositories — for example:

```
feat(engine): add parallel compensation strategy
fix(builder): reject duplicate step names on register
docs(readme): add saga compensation example for the publish step
```

Co-Authored-By trailers for AI-assisted commits are encouraged.

## Code of conduct

Participation in this project is subject to the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security issues

Do not file public issues for vulnerabilities. See [SECURITY.md](SECURITY.md) for the responsible-disclosure policy.

## License

By contributing, you agree your contribution is released under the [Apache-2.0 license](LICENSE).
