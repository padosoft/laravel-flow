# Changelog

All notable changes to `padosoft/laravel-flow` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Persistence foundation** — publishable `flow_runs`, `flow_steps`, and `flow_audit` migrations; public `FlowStore`, `RunRepository`, `StepRunRepository`, and `AuditRepository` contracts; Eloquent-backed records/repositories; append-only audit record guard; configurable payload redaction for stored JSON payloads.

### Changed

- **Baseline compatibility policy** — Composer constraints and CI now target Laravel 13 only, with PHP 8.3 and 8.4 as stable hard gates. Package quality commands are exposed through Composer scripts: `format:test`, `analyse`, `test`, and `quality`.
- **Runtime dependencies** — `illuminate/database` is now a production dependency because v0.2 persistence models and repositories are part of the package runtime surface.

## [0.1.0] - 2026-05-02

### Added

- **W5 — full scaffold expansion + initial Flow engine core.**
  - **Scaffold completion.** Full `.claude/` vibe-coding pack imported from the Padosoft baseline (skills, rules, agents, commands, instructions); `.github/workflows/ci.yml` matrix on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 with Pint + PHPStan + PHPUnit Unit + Architecture suites; `phpunit.xml` Unit + Architecture + opt-in Live testsuite split; `pint.json` + `phpstan.neon.dist` aligned with the Padosoft baseline; `config/laravel-flow.php` with five tunables (`default_storage`, `audit_trail_enabled`, `dry_run_default`, `step_timeout_seconds`, `compensation_strategy`); `LaravelFlowServiceProvider` registers the engine as a container singleton and publishes the config under the `laravel-flow-config` tag; `composer.json` trimmed to Laravel 12 / 13 + PHP 8.3 minimum and aligned with the Padosoft package baseline; `.editorconfig` + `.gitattributes` shipped; README rewritten as a 14-section WOW document covering theory, comparison vs Spatie Workflow / Symfony Workflow / Temporal / AWS Step Functions, installation, quick start, usage examples, configuration reference, architecture diagram, AI vibe-coding pack section, testing strategy, and roadmap.
  - **Core engine.** `FlowEngine` (in-memory definition registry + execute / dryRun + reverse-order compensation walker), `FlowDefinitionBuilder` (fluent API: `withInput()`, `step()`, `withDryRun()`, `compensateWith()`, `withAggregateCompensator()`, `register()`), `FlowDefinition` + `FlowStep` (readonly DTOs), `FlowStepHandler` + `FlowCompensator` (interfaces resolved through the Laravel container), `FlowContext` (readonly carrier with input + accumulated step outputs + dry-run flag), `FlowStepResult` (readonly DTO with success / output / error / businessImpact / dryRunSkipped), `FlowRun` (status machine: pending / running / succeeded / failed / compensated / aborted, plus failedStep / compensated / stepResults / startedAt / finishedAt), `Facades\Flow` exposing the engine.
  - **Exceptions.** `FlowException` (non-final base extending `RuntimeException`), `FlowInputException`, `FlowNotRegisteredException`, `FlowExecutionException`, `FlowCompensationException`.
  - **Events.** `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated` — audit trail emitted via the Laravel event dispatcher; can be globally muted via `audit_trail_enabled = false`.
  - **Test suite.** Unit suite covering builder fluency + register error paths (`FlowDefinitionBuilderTest`), happy-path execution + input validation + dry-run skip semantics + uuid generation + step output accumulation (`FlowEngineTest`), reverse-order compensation + no-compensator-on-first-step + payload pass-through (`FlowEngineCompensationTest`), event emission per transition + dry-run flag propagation + audit-disabled silencing (`FlowEventEmissionTest`), Facade round-trip (`FlowFacadeTest`); architecture test (`StandaloneAgnosticTest`) walks `src/` recursively with `RecursiveDirectoryIterator` and asserts no AskMyDocs / sister-package symbols leak into production code; opt-in Live placeholder under `tests/Live/`.

### Changed

- **`LaravelFlowServiceProvider`** — was a no-op skeleton; W5 ships the real bindings (engine singleton + config publish).
- **`composer.json`** — dropped `^11.0` from the `illuminate/*` requires (v4.0 minimum is Laravel 12); dropped `orchestra/testbench: ^9.0`; added the `Flow` Facade alias under `extra.laravel.aliases`; added a `suggest` entry for `padosoft/laravel-patent-box-tracker` (R&D activity tracking on repos that depend on `laravel-flow`).
- **`README.md`** — replaced 49-line draft with a 500+ line WOW document.

### Removed

- N/A.

[Unreleased]: https://github.com/padosoft/laravel-flow/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/padosoft/laravel-flow/releases/tag/v0.1.0
