# Copilot Instructions For Laravel Flow

This repository is a Laravel package, not an application.

Important project context:

- Enterprise target is Laravel 13-only and PHP `^8.3`; current v0.1 metadata may still support Laravel 12/13 until the baseline tooling macro narrows it.
- Dashboard work belongs in a companion app, not embedded in this package.
- Core code in `src/` must remain standalone-agnostic and must not reference AskMyDocs, the companion dashboard, or app-specific symbols.
- Dry-run behavior must not perform real side effects.
- Compensation must remain reverse-order by default and must preserve failure context.
- Persisted audit and webhook payloads must not expose secrets.
- Public API changes require tests and documentation.
- Feature additions or material feature improvements should update README section `Comparison vs alternatives`; competitor claims should be accurate and researched when uncertain.

Review priorities:

- Bugs in flow status transitions, dry-run skip behavior, compensation ordering, replay semantics, idempotency, approval token handling, webhook signing, and data redaction.
- Missing tests for public API behavior.
- Laravel package ergonomics: service provider, config publish tags, migrations, commands, Testbench coverage.
- CI/tooling drift between README claims and actual gates.
