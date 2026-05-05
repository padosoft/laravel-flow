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
- v1.0 stability: classes are annotated with `@api` (SemVer-covered) or `@internal` (implementation detail). Never combine the two. Do not change the public method signature, public constants, or class name of an `@api` type without bumping the major version and updating `docs/UPGRADE.md` plus `tests/Contract/PublicApiContractTest.php`.
- Internal namespaces today (`Persistence`, `Models`, `Queue`, `Jobs`, `Console`) are not part of the public contract. Surface new extension points through `Padosoft\LaravelFlow\Contracts\*` interfaces.
- `DashboardActionAuthorizer` is bound to `DenyAllAuthorizer` by default. Reject changes that flip this default to permissive; production deployments rely on the deny-by-default posture.
- Plain approval tokens are never stored. The dashboard authorizer takes a token hash; flag any code path that persists or logs a plain token.
- Companion dashboard work belongs in `padosoft/padosoft-laravel-flow-dashboard`. The package must remain headless. The dashboard brief is at `docs/DASHBOARD_APP_SPEC.md`.

Review priorities:

- Bugs in flow status transitions, dry-run skip behavior, compensation ordering, replay semantics, idempotency, approval token handling, webhook signing, and data redaction.
- Missing tests for public API behavior.
- Laravel package ergonomics: service provider, config publish tags, migrations, commands, Testbench coverage.
- CI/tooling drift between README claims and actual gates.
