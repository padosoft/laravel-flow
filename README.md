# laravel-flow

[![Tests](https://github.com/padosoft/laravel-flow/actions/workflows/ci.yml/badge.svg)](https://github.com/padosoft/laravel-flow/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/padosoft/laravel-flow.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow)
[![PHP Version](https://img.shields.io/packagist/php-v/padosoft/laravel-flow.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow)
[![Laravel Version](https://img.shields.io/badge/Laravel-13.x-red?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/laravel-flow.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow)

> **DX-first workflow / saga / compensation engine for Laravel — with native dry-run, reverse-order rollback, business-impact projection, opt-in persistence, and audit events. Built for Laravel teams that need dry-run, compensation, and persisted run telemetry inside the app they already operate.**

`laravel-flow` is the third deliverable of the [Padosoft v4.0 cycle](https://github.com/lopadova/AskMyDocs) (W5). It is a community Apache-2.0 package, **standalone-agnostic** (zero references to AskMyDocs / sister packages), and ships with the Padosoft AI vibe-coding pack so you can extend it with Claude Code or GitHub Copilot in minutes — not days.

```php
use Padosoft\LaravelFlow\Facades\Flow;

Flow::define('promotion.create')
    ->withInput(['brand', 'discount_pct', 'starts_at', 'ends_at'])
    ->step('validate', ValidatePromotionInput::class)
    ->step('simulate', SimulatePromotionImpact::class)
        ->withDryRun(true)
    ->step('persist', PersistPromotion::class)
        ->compensateWith(ReversePromotion::class)
    ->register();

$run    = Flow::execute('promotion.create', $input);   // real execution
$dryRun = Flow::dryRun ('promotion.create', $input);   // simulate, no writes
```

---

## Table of contents

- [Why this package](#why-this-package)
- [Design rationale](#design-rationale)
- [Features at a glance](#features-at-a-glance)
- [Comparison vs alternatives](#comparison-vs-alternatives)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Usage examples](#usage-examples)
- [Configuration reference](#configuration-reference)
- [Architecture](#architecture)
- [AI vibe-coding pack](#ai-vibe-coding-pack)
- [Testing — Default + Live](#testing--default--live)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

---

## Why this package

Laravel applications routinely need to orchestrate **multi-step business workflows** that mix:

- **Validations** (some safe to skip, some load-bearing).
- **Simulations** (project the impact of an operation without writing).
- **Manual sign-off checkpoints** (planned for the v0.3 approval/webhook macro; not shipped in the current core).
- **Side-effecting writes** (DB rows, queue jobs, vendor API calls).
- **Compensation chains** (when step N fails, undo step N-1 ... step 1).
- **Audit trails** (regulators want to see *who did what, when, with which inputs, in which order*).

The Laravel ecosystem has plenty of tools for *some* of these — `Bus::chain()` for sequence, jobs for async, `transaction()` for atomicity — but none of them ship with **native dry-run**, **reverse-order saga compensation**, and **a single fluent surface** that a junior dev can read in 30 seconds.

`laravel-flow` is that surface.

It is **deliberately small**. v0.1 is in-memory, synchronous, container-resolved. The current v0.2 foundation adds opt-in DB persistence for runs, steps, and audit rows; queued workers, replay, and compensation strategy expansion remain planned v0.2 slices, with v0.3 human checkpoint/webhook support and the companion dashboard in later macros.

---

## Design rationale

Five non-negotiable choices that drove the API:

### 1. Dry-run is a first-class flag, not a convention

Every handler can declare `->withDryRun(true)`. When the engine runs in dry mode, it **invokes** dry-run-aware steps (so they can project impact) and **skips** the others, returning `dry_run_skipped` markers. There is no separate "preview" code path to maintain.

### 2. Compensation walks backwards by default

Saga semantics: when step N fails, the engine walks the previously-completed steps from N-1 back to 1, calling each registered `FlowCompensator`. There is no "compensate forward" or "best-effort cleanup" mode — predictable rollback every time.

### 3. Handlers and compensators are container-resolved classes

`step('persist', PersistPromotion::class)` not `step('persist', fn () => ...)`. Closures don't survive serialization (queued workers in v0.2), don't get DI, and don't surface in stack traces. Class-based handlers cost one extra file and pay back tenfold in observability.

### 4. The audit trail is event-driven

When `audit_trail_enabled` is enabled, normal-case step and compensation transitions dispatch the matching Laravel event, such as `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, or `FlowCompensated`. When persistence is enabled, step events are dispatched only after the matching audit append succeeds, and compensation events are skipped if their audit append fails. The host application subscribes once and routes those events to the logger, DB, or metrics backend it already runs. Persisted `flow_audit` rows are written only for non-dry-run executions when both persistence and `audit_trail_enabled` are enabled. Dry-runs never write run, step, or audit rows.

### 5. Standalone-agnostic — zero AskMyDocs symbols

`laravel-flow` is a **community** package. It is not coupled to AskMyDocs, the sister patent-box-tracker, or any other Padosoft project. An architecture test enforces this on every CI run by walking `src/` with `RecursiveDirectoryIterator` and asserting forbidden substrings never appear.

---

## Features at a glance

- **Fluent definition builder** — `Flow::define($name)->withInput([...])->step(...)->register()`.
- **Native dry-run** — `Flow::dryRun($name, $input)` simulates without persisting; supporting handlers project impact, others self-skip.
- **Reverse-order saga compensation** — `compensateWith(Compensator::class)` per step; failures unwind cleanly.
- **Audit events and persisted audit rows** — normal-case transitions dispatch matching `FlowStep*` / `FlowCompensated` events when `audit_trail_enabled=true`; persisted `flow_audit` rows are written only for non-dry-run executions with both `persistence.enabled=true` and `audit_trail_enabled=true`, and those rows are append-only during normal runtime but retention-prunable with `flow:prune`.
- **Business-impact projection** — handlers return `businessImpact: [...]` alongside output, surfaced on every step result.
- **Opt-in persisted execution** — `flow_runs`, `flow_steps`, and `flow_audit` migrations, Eloquent repositories, immutable run identity updates, correlation/idempotency keys, transaction-scoped run/step/audit transitions, compensate-first runtime-abort recovery, sanitized listener/error storage, clock-aware audit timestamps, redacted JSON payload storage, and retention pruning.
- **Container-resolved handlers** — full DI, type hints, and stack traces.
- **Strict input validation** — `withInput(['a','b'])` throws `FlowInputException` if a key is missing.
- **Compensation strategy config** — the shipped value is `reverse-order`; `parallel` is documented only as a reserved future value and currently executes reverse-order.
- **Testbench-friendly** — TestCase + stubs ready to copy.
- **🚀 AI vibe-coding pack included** — `.claude/` directory with skills, rules, agents, commands, and the Padosoft Copilot review loop pre-wired.
- **PHP 8.3 / 8.4 × Laravel 13** matrix on every CI run.

---

## Comparison vs alternatives

Legend: `✅ YES` means the capability is first-class in the current product, `⚠️ PARTIAL` means it is possible but manual, narrower, or provided through a different model, and `❌ NO` means it is not available today.

| Feature | `laravel-flow` | Durable Workflow (Laravel) | Symfony Workflow | Temporal | AWS Step Functions |
| --- | --- | --- | --- | --- | --- |
| Native dry-run with no persistence writes | ✅ YES - first-class `Flow::dryRun()`; no run, step, audit, or compensator writes | ❌ NO - not documented as a first-class mode | ❌ NO - app must model preview behavior | ❌ NO - app must model simulation separately | ❌ NO - app must model simulation separately |
| Reverse-order saga compensation | ✅ YES - built-in per-step `compensateWith()` | ⚠️ PARTIAL - sagas/error handling are possible, but compensation policy is workflow-defined | ⚠️ PARTIAL - manual transition/state design | ⚠️ PARTIAL - compensation pattern via workflow code/SDKs | ⚠️ PARTIAL - manual cleanup states via `Catch` |
| Approval gate as a step type | ❌ NO - planned for v0.3 approval/webhook macro | ⚠️ PARTIAL - model manually as a long-running workflow/activity | ⚠️ PARTIAL - guards can block transitions but are not resumable approval steps | ⚠️ PARTIAL - signals/await patterns, not Laravel step gates | ✅ YES - callback/task-token pattern |
| Container-resolved PHP handlers | ✅ YES - handlers and compensators resolve through Laravel's container | ✅ YES - PHP workflow/activity classes | ✅ YES - Symfony services/listeners | ❌ NO - worker model is outside Laravel's container | ❌ NO - Lambda/service fanout |
| Audit trail and event hooks | ✅ YES - `FlowStep*` / `FlowCompensated` events plus optional `flow_audit` rows | ⚠️ PARTIAL - status tracking and Laravel event integration | ✅ YES - workflow events and optional audit trail | ✅ YES - managed workflow event history | ✅ YES - execution history plus CloudWatch/CloudTrail integrations |
| In-memory default with opt-in app DB persistence | ✅ YES - memory by default; DB runs/steps/audit only when enabled | ❌ NO - durable persistence is central to the engine | ⚠️ PARTIAL - marking store is app-defined | ❌ NO - dedicated Temporal service/cluster | ❌ NO - managed AWS service |
| Redacted JSON persistence | ✅ YES - configurable key redaction before run/step/audit payload storage | ❌ NO - not documented as built-in | ❌ NO - app-defined storage concern | ⚠️ PARTIAL - custom payload codecs/converters, not Laravel key config | ⚠️ PARTIAL - service-level data handling, not Laravel key config |
| Correlation and idempotency keys | ✅ YES - first-class `FlowExecutionOptions` with length validation and persisted reuse | ❌ NO - not documented as first-class execution metadata | ❌ NO - app-defined | ⚠️ PARTIAL - workflow/activity IDs and idempotency patterns | ⚠️ PARTIAL - execution names/tokens, service-specific semantics |
| Successful-step output aggregation | ✅ YES - persisted successful outputs rehydrate idempotent run reuse | ⚠️ PARTIAL - workflow/activity outputs exist, but this package contract is not documented | ❌ NO - app-defined | ✅ YES - workflow history/result model | ⚠️ PARTIAL - state input/output paths, not Laravel step result objects |
| Transaction-scoped transition writes | ✅ YES - run, step, and audit transitions share repository transactions | ⚠️ PARTIAL - package/app persistence model | ⚠️ PARTIAL - marking store/app transaction concern | ✅ YES - managed event-history durability | ✅ YES - managed execution-history durability |
| Runtime-abort recovery before surfacing infrastructure failures | ✅ YES - best-effort failure state plus compensation before rethrow | ⚠️ PARTIAL - retries/error handling, recovery policy is workflow-defined | ⚠️ PARTIAL - app-defined | ✅ YES - durable execution/retry recovery | ⚠️ PARTIAL - `Retry`, `Catch`, and redrive behavior |
| Retention pruning for persisted telemetry | ✅ YES - `flow:prune` keeps pending/running rows intact | ❌ NO - not documented as built-in | ❌ NO - app-defined | ⚠️ PARTIAL - service retention configuration, not package command | ⚠️ PARTIAL - managed history/log retention, not app command |
| Business-impact projection on every result | ✅ YES - `businessImpact` is part of every `FlowStepResult` | ❌ NO - not documented | ❌ NO - not a workflow component concern | ❌ NO - app-defined | ❌ NO - app-defined |
| Queue-backed workers today | ❌ NO - planned v0.2 slice | ✅ YES - Laravel queue/worker support | ❌ NO - not native | ✅ YES - worker-based execution | ✅ YES - managed orchestration |
| Replay/redrive of failed executions today | ❌ NO - planned v0.2 slice | ⚠️ PARTIAL - durable long-running workflow model; exact replay semantics differ | ❌ NO - not native | ✅ YES - deterministic replay/event history | ✅ YES - Standard Workflow redrive |
| Low setup friction | ✅ YES - `composer require` plus optional config/migration publish | ⚠️ PARTIAL - Laravel queues/workers and optional Waterline UI | ✅ YES - Composer package and framework config | ❌ NO - service/cluster plus workers | ❌ NO - AWS account, IAM, state-machine definitions |
| Self-hosted with no external workflow service | ✅ YES - runs inside the Laravel app; DB optional | ✅ YES - Laravel app/queue infrastructure | ✅ YES - application component | ❌ NO - requires Temporal service/cluster | ❌ NO - AWS-managed service |
| Open-source package/license | ✅ YES - Apache-2.0 | ✅ YES - MIT | ✅ YES - MIT | ✅ YES - MIT core/server and SDKs | ❌ NO - proprietary managed service |

Competitor snapshot checked against Durable Workflow, Symfony Workflow, Temporal, and AWS Step Functions documentation on 2026-05-03. `laravel-flow` is **deliberately positioned** as the lightest Laravel-native dependency in the table. If you already run Temporal or AWS Step Functions and need their queue/replay/redrive guarantees today, use them. If you want saga semantics, dry-run, business-impact projection, and opt-in persistence inside an existing Laravel app, this is the package.

---

## Installation

```bash
composer require padosoft/laravel-flow
```

Publish the config (optional — the engine works with defaults):

```bash
php artisan vendor:publish --tag=laravel-flow-config
```

Publish the v0.2 persistence migrations only when you are opting into DB-backed storage:

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

The in-memory engine path still works without migrations. To persist runtime runs, enable `LARAVEL_FLOW_PERSISTENCE_ENABLED=true`; dry-runs remain simulation-only and do not write to the database.

Prune old terminal persistence records with the built-in retention command:

```bash
php artisan flow:prune --days=90 --dry-run
php artisan flow:prune --days=90
```

`flow:prune` deletes only terminal runs (`succeeded`, `failed`, `compensated`, `aborted`) with `finished_at` older than the cutoff. Matching `flow_steps` and `flow_audit` rows are deleted in the same batch transaction; running and pending rows are left untouched. Use `LARAVEL_FLOW_RETENTION_DAYS=90` to make `--days` optional, and `--force` for non-interactive production runs.

> **Requirements**
> - PHP 8.3+
> - Laravel 13.x

---

## Quick start

```php
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

// 1. Define a handler.
class ValidatePromotionInput implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        if ($context->input['discount_pct'] > 90) {
            return FlowStepResult::failed(new \DomainException('Discount > 90%'));
        }

        return FlowStepResult::success(['validated_at' => now()->toIso8601String()]);
    }
}

// 2. Register a flow.
Flow::define('promotion.create')
    ->withInput(['brand', 'discount_pct'])
    ->step('validate', ValidatePromotionInput::class)
    ->register();

// 3. Execute.
$run = Flow::execute('promotion.create', ['brand' => 'acme', 'discount_pct' => 25]);

if ($run->status === \Padosoft\LaravelFlow\FlowRun::STATUS_SUCCEEDED) {
    // Done.
} else {
    // $run->failedStep tells you which step blew up.
    // $run->compensated tells you whether rollback ran.
}
```

---

## Usage examples

### Correlation and idempotency

```php
use Padosoft\LaravelFlow\FlowExecutionOptions;

$run = Flow::execute(
    'promotion.create',
    $input,
    FlowExecutionOptions::make(
        correlationId: 'checkout-2026-0001',
        idempotencyKey: 'tenant-42:promotion-abc',
    ),
);
```

When persistence is enabled, `correlationId` and `idempotencyKey` are stored on `flow_runs`. Both values are trimmed, empty strings become `null`, and non-empty values are limited to 255 characters to match the published migrations. A later persisted execution with the same idempotency key returns the existing run state without executing handlers again. Dry-runs still avoid persistence writes.

### Compensation chain (saga rollback)

```php
class PersistPromotion implements FlowStepHandler { /* writes a DB row */ }
class ReversePromotion implements FlowCompensator  { /* deletes the row */ }

Flow::define('promotion.create')
    ->withInput(['brand', 'discount_pct'])
    ->step('validate', ValidatePromotionInput::class)
    ->step('persist', PersistPromotion::class)
        ->compensateWith(ReversePromotion::class)
    ->step('publish', PublishPromotionToCDN::class)  // imagine this fails
    ->register();

$run = Flow::execute('promotion.create', $input);

// If 'publish' fails:
//   $run->status      === FlowRun::STATUS_FAILED
//   $run->failedStep  === 'publish'
//   $run->compensated === true   // ReversePromotion ran
```

### Dry-run / impact projection

```php
class SimulatePromotionImpact implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        $impact = [
            'expected_users_reached' => 12_400,
            'projected_revenue_eur'  => 18_900.00,
        ];

        return FlowStepResult::success(output: [], businessImpact: $impact);
    }
}

Flow::define('promotion.create')
    ->step('simulate', SimulatePromotionImpact::class)
        ->withDryRun(true)
    ->step('persist', PersistPromotion::class)        // skipped in dry mode
    ->register();

$dryRun = Flow::dryRun('promotion.create', $input);

$businessImpact = $dryRun->stepResults['simulate']->businessImpact;
// ['expected_users_reached' => 12400, 'projected_revenue_eur' => 18900.00]
```

### Subscribing to the audit trail

```php
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowCompensated;

Event::listen(function (FlowStepStarted $e) {
    logger()->info('flow.step.started', [
        'flow_run_id'     => $e->flowRunId,
        'definition_name' => $e->definitionName,
        'step_name'       => $e->stepName,
        'dry_run'         => $e->dryRun,
    ]);
});

Event::listen(function (FlowStepFailed $e) {
    // Wire to Sentry / Datadog / your audit table.
});
```

---

## Configuration reference

```php
// config/laravel-flow.php
return [
    'default_storage'        => env('LARAVEL_FLOW_STORAGE', null),
    'persistence'            => [
        'enabled'   => env('LARAVEL_FLOW_PERSISTENCE_ENABLED', false),
        'redaction' => [
            'enabled'     => env('LARAVEL_FLOW_REDACTION_ENABLED', true),
            'replacement' => env('LARAVEL_FLOW_REDACTION_REPLACEMENT', '[redacted]'),
            'keys'        => ['api_key', 'authorization', 'password', 'secret', 'token'],
        ],
        'retention' => [
            'days' => env('LARAVEL_FLOW_RETENTION_DAYS', null),
        ],
    ],
    'audit_trail_enabled'    => env('LARAVEL_FLOW_AUDIT_ENABLED', true), // events; DB audit rows also require persistence.enabled=true and non-dry-run
    'dry_run_default'        => env('LARAVEL_FLOW_DRY_RUN_DEFAULT', false),
    'step_timeout_seconds'   => (int) env('LARAVEL_FLOW_STEP_TIMEOUT', 300), // v0.2
    'compensation_strategy'  => env('LARAVEL_FLOW_COMPENSATION', 'reverse-order'),
];
```

| Key                       | Default          | Effect                                                                                            |
| ------------------------- | ---------------- | ------------------------------------------------------------------------------------------------- |
| `default_storage`         | `null`           | DB connection used by persistence repositories. Inherits app default when `null`.                 |
| `persistence.enabled`     | `false`          | Enables synchronous engine writes to `flow_runs` and `flow_steps`; `flow_audit` writes also require `audit_trail_enabled=true` and a non-dry-run execution. Dry-runs do not write. |
| `persistence.redaction`   | common secrets   | Redacts configured JSON payload keys before run, step, and audit payloads are stored.             |
| `persistence.retention.days` | `null`         | Default retention window for `php artisan flow:prune`; pass `--days` to override per run.         |
| `audit_trail_enabled`     | `true`           | When `false`, suppresses every `FlowStep*` / `FlowCompensated` event and persisted audit row; persisted audit rows also require persistence and non-dry-run execution. |
| `dry_run_default`         | `false`          | When `true`, `Flow::execute()` behaves like `dryRun()` — guard rail for staging environments.     |
| `step_timeout_seconds`    | `300`            | Reserved for v0.2 queued workers.                                                                 |
| `compensation_strategy`   | `reverse-order`  | `parallel` reserved for v0.2 — currently falls back to reverse-order.                             |

When persistence is enabled, synchronous `FlowStep*` listener or persistence failures are rethrown after the engine records best-effort recovery state and compensates completed steps. `FlowCompensated` listener failures are swallowed after the compensation audit row is durable so rollback is not interrupted. Wrap `Flow::execute()` in application-level exception handling anywhere infrastructure outages must be surfaced separately from business step failures.

Custom `FlowStore` implementations that need the same per-execution `PayloadRedactor` used by engine error-text sanitization should implement `Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore`. The engine calls `withPayloadRedactor()` once per persisted execution before writing run, step, and audit telemetry; implementations that keep transaction state on the store should return the same instance or a state-sharing decorator. `PayloadRedactor` decorators that wrap the package execution-scoped redactor should implement `Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider` so multi-field repository writes can reuse one stable inner redactor without changing each JSON payload shape.

---

## Architecture

```
┌────────────────────┐     define / register      ┌─────────────────────┐
│ FlowDefinitionBuilder ──────────────────────────► FlowDefinition       │
└──────────┬─────────┘                             │  - name             │
           │                                       │  - requiredInputs   │
           │                                       │  - list<FlowStep>   │
           │                                       └──────────┬──────────┘
           │                                                  │
           │  Flow::execute / Flow::dryRun                    ▼
           ▼                                       ┌─────────────────────┐
   ┌──────────────────┐  iterate steps             │ FlowEngine          │
   │   FlowEngine     │ ───────────────────────────►   - definitions[]   │
   │  ::execute()     │                            │   - container DI    │
   └──────┬───────────┘                            │   - event dispatch  │
          │                                        └─────────┬───────────┘
          │ container->make(handlerFqcn)                     │
          ▼                                                  │
   ┌──────────────────┐                                      │
   │ FlowStepHandler  │ ──► FlowStepResult ─────────────────►│
   └──────────────────┘                                      │
          │ failure                                          │
          ▼                                                  │
   ┌──────────────────┐                                      │
   │ Compensation     │ walks backwards                      │
   │ ::reverse-order  │ ──► FlowCompensator::compensate()    │
   └──────────────────┘                                      │
                                                             ▼
                                                   ┌─────────────────────┐
                                                   │ FlowRun             │
                                                   │  - id (uuid v4)     │
                                                   │  - status           │
                                                   │  - failedStep       │
                                                   │  - compensated      │
                                                   │  - stepResults{}    │
                                                   │  - startedAt/finishedAt
                                                   └─────────────────────┘
```

Every box is one PHP class under `src/`. The engine path is still synchronous and in-memory by default; when persistence is enabled, runtime runs and steps are written to `flow_runs` and `flow_steps` for non-dry-run executions. Audit transitions are written to `flow_audit` only for non-dry-run executions while persistence and `audit_trail_enabled` are both enabled. Dry-runs never write audit rows. The next v0.2 slices add queues, replay, and compensation strategy expansion.

---

## AI vibe-coding pack

🚀 **Every Padosoft package ships with the same vibe-coding pack** — drop the `.claude/` directory into Claude Code or GitHub Copilot and you get:

- **Skills** under `.claude/skills/` — reviewer-validated playbooks for `copilot-pr-review-loop`, `pre-push-self-review`, `test-count-readme-sync`, and more.
- **Rules** under `.claude/rules/` — coding standards (type hints, early return, no debug in commits, code structure, naming conventions, PR workflow). For `laravel-flow`, repo-local rules define the Laravel 13 package baseline and PR workflow.
- **Agents** under `.claude/agents/` — pre-wired sub-agent definitions (`admin-interface-architect`, `playwright-enterprise-tester`).
- **Commands** under `.claude/commands/` — slash-command templates (`/create-job`, `/domain-scaffold`, `/playwright-tester`, `/pagespeed-review`).
- **Instructions** under `.claude/instructions/` — runtime safety guardrails (`testing-safety.md`).

The pack is the same baseline used across all `padosoft/*` repos. It is opt-in: delete `.claude/` if you don't use Claude Code or Copilot — nothing else depends on it.

---

## Testing — Default + Live

The default `phpunit` invocation runs only the offline `Unit` + `Architecture` testsuites and never makes a network call:

```bash
composer install
composer validate --strict --no-check-publish
composer format:test
composer analyse
composer test
```

The `Live` testsuite is **opt-in** and reserved for v0.2+ scenarios that need a real external dependency (queue worker, webhook receiver). Every Live test self-skips unless `LARAVEL_FLOW_LIVE=1` is set:

```bash
LARAVEL_FLOW_LIVE=1 vendor/bin/phpunit --testsuite Live
```

CI runs Pint (style), PHPStan (level 6), and the Unit + Architecture suites through Composer scripts on the PHP 8.3 / 8.4 × Laravel 13 matrix for pushes to `main` and PRs targeting `main` or `task/**`. PHP 8.5 is intentionally not a hard gate until Laravel/Testbench dependency support is reliable enough for this package.

---

## Roadmap

| Version | Scope                                                                                                                                                                                                                                                              | Target            |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- |
| v0.1    | In-memory engine, fluent builder, dry-run, reverse-order compensation, four audit event classes, business-impact field on results, Facade. Architecture test enforces standalone-agnostic.                                                                            | code complete     |
| v0.2    | Persistence core: `flow_runs` / `flow_steps` / `flow_audit` tables, synchronous engine writes, redacted payload storage, correlation/idempotency keys, and terminal-run retention pruning. Queue-backed workers, replay command, and parallel compensation strategy remain next. | Q3 2026           |
| v0.3    | Approval-gate primitive (a step type that pauses until an external token is presented), webhooks for resume.                                                                                                                                                         | Q4 2026           |
| v1.0    | Stable API, semver guarantee, full migration helpers from Durable Workflow / Symfony Workflow.                                                                                                                                                                       | 2027              |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Community PRs target `main`; enterprise roadmap work uses the macro/subtask PR loop documented in `AGENTS.md`.

---

## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities privately to `lorenzo.padovani@padosoft.com`.

---

## License

[Apache-2.0](LICENSE).

Built by [Padosoft](https://padosoft.com) — part of the [v4.0 ecosystem](https://github.com/lopadova/AskMyDocs).
