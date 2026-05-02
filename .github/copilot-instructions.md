# Copilot Instructions For Laravel Flow

This repository is a Laravel package, not an application.

Important project context:

- Review code against the active Composer/CI matrix. After Macro Task 1, the package supports Laravel 13 and PHP `^8.3`, with CI hard gates on PHP 8.3 and 8.4.
- Dashboard work belongs in a companion app, not embedded in this package.
- Core code in `src/` must remain standalone-agnostic and must not reference AskMyDocs, the companion dashboard, or app-specific symbols.
- Dry-run behavior must not perform real side effects.
- Compensation must remain reverse-order by default and must preserve failure context.
- Persisted audit and webhook payloads must not expose secrets.
- Public API changes require tests and documentation.
- Feature additions or material feature improvements must update README section `Comparison vs alternatives`; competitor claims must be accurate and researched when uncertain.

Review priorities:

- Bugs in flow status transitions, dry-run skip behavior, compensation ordering, replay semantics, idempotency, approval token handling, webhook signing, and data redaction.
- Missing tests for public API behavior.
- Laravel package ergonomics: service provider, config publish tags, migrations, commands, Testbench coverage.
- CI/tooling drift between README claims and actual gates.
