# laravel-flow

[![Tests](https://github.com/padosoft/laravel-flow/actions/workflows/ci.yml/badge.svg)](https://github.com/padosoft/laravel-flow/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/padosoft/laravel-flow.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow)
[![PHP Version](https://img.shields.io/packagist/php-v/padosoft/laravel-flow.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-red?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/laravel-flow.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow)

> **DX-first workflow / saga / compensation engine for Laravel — with native dry-run, reverse-order rollback, approval gate, business-impact projection, and an immutable audit trail. Built for the Laravel team that needs Temporal-class semantics without leaving Eloquent.**

`laravel-flow` is the third deliverable of the [Padosoft v4.0 cycle](https://github.com/lopadova/AskMyDocs) (W5). It is a community Apache-2.0 package, **standalone-agnostic** (zero references to AskMyDocs / sister packages), and ships with the Padosoft AI vibe-coding pack so you can extend it with Claude Code or GitHub Copilot in minutes — not days.

```php
use Padosoft\LaravelFlow\Facades\Flow;

Flow::define('promotion.create')
    ->withInput(['brand', 'discount_pct', 'starts_at', 'ends_at'])
    ->step('validate', ValidatePromotionInput::class)
    ->step('simulate', SimulatePromotionImpact::class)
        ->withDryRun(true)
    ->step('approval', RequiresHumanApproval::class)
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
- **Approval gates** (a human signs off before persistence).
- **Side-effecting writes** (DB rows, queue jobs, vendor API calls).
- **Compensation chains** (when step N fails, undo step N-1 ... step 1).
- **Audit trails** (regulators want to see *who did what, when, with which inputs, in which order*).

The Laravel ecosystem has plenty of tools for *some* of these — `Bus::chain()` for sequence, jobs for async, `transaction()` for atomicity — but none of them ship with **native dry-run**, **reverse-order saga compensation**, and **a single fluent surface** that a junior dev can read in 30 seconds.

`laravel-flow` is that surface.

It is **deliberately small**. v0.1 is in-memory, synchronous, container-resolved. v0.2 will bring queued workers, persisted runs, and a web dashboard. The core engine fits in ~250 lines of PHP and 30+ unit tests describe every transition.

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

Every transition (`FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated`) is a Laravel event. The host application subscribes once and routes them to the logger / DB / metrics backend it already runs. v0.2 will add a default `flow_audit` table; v0.1 deliberately leaves the destination open.

### 5. Standalone-agnostic — zero AskMyDocs symbols

`laravel-flow` is a **community** package. It is not coupled to AskMyDocs, the sister patent-box-tracker, or any other Padosoft project. An architecture test enforces this on every CI run by walking `src/` with `RecursiveDirectoryIterator` and asserting forbidden substrings never appear.

---

## Features at a glance

- **Fluent definition builder** — `Flow::define($name)->withInput([...])->step(...)->register()`.
- **Native dry-run** — `Flow::dryRun($name, $input)` simulates without persisting; supporting handlers project impact, others self-skip.
- **Reverse-order saga compensation** — `compensateWith(Compensator::class)` per step; failures unwind cleanly.
- **Immutable audit trail** — four Laravel events per transition; subscribe once.
- **Business-impact projection** — handlers return `businessImpact: [...]` alongside output, surfaced on every step result.
- **Container-resolved handlers** — full DI, type hints, and stack traces.
- **Strict input validation** — `withInput(['a','b'])` throws `FlowInputException` if a key is missing.
- **Multi-strategy compensation knob** — `reverse-order` (default), `parallel` (v0.2).
- **Testbench-friendly** — TestCase + stubs ready to copy.
- **🚀 AI vibe-coding pack included** — `.claude/` directory with skills, rules, agents, commands, and the Padosoft Copilot review loop pre-wired.
- **PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13** matrix on every CI run.

---

## Comparison vs alternatives

| Feature                          | `laravel-flow`               | Spatie Workflow             | Symfony Workflow            | Temporal                  | AWS Step Functions     |
| -------------------------------- | ---------------------------- | --------------------------- | --------------------------- | ------------------------- | ---------------------- |
| Native dry-run                   | ✅ first-class                | ❌                          | ❌                          | ❌                         | ❌                      |
| Reverse-order saga compensation  | ✅ built-in                   | ⚠️ manual                   | ⚠️ manual                   | ✅ via SDK                 | ⚠️ via Catch + state    |
| Approval gate as a step type     | ✅ via handler contract       | ⚠️ via guards               | ✅ via transition guard     | ⚠️ via `Workflow.await`   | ✅ via task token        |
| Container-resolved handlers      | ✅                            | ⚠️ partial                  | ✅                          | ✅ (via worker DI)         | ❌ (Lambda fanout)      |
| Audit trail (events)             | ✅ 4 events / transition      | ⚠️ via state machine hooks  | ✅                          | ✅                         | ✅ (CloudWatch)         |
| Business-impact projection       | ✅ on every result            | ❌                          | ❌                          | ❌                         | ❌                      |
| Persistence model                | in-memory (v0.1) → DB (v0.2) | DB                          | DB                          | dedicated cluster         | managed                |
| Setup time                       | `composer require` + 1 file  | medium                      | medium                      | run a Temporal cluster    | AWS account + IAM      |
| Self-hosted, zero infra          | ✅                            | ✅                           | ✅                          | ❌ (cluster needed)        | ❌ (AWS-only)           |
| License                          | Apache-2.0                   | MIT                         | MIT                         | MIT                       | proprietary            |

`laravel-flow` is **deliberately positioned** as the lightest dependency in the table. If you are already running a Temporal cluster, use Temporal. If you are running zero infra and want saga semantics in your existing Laravel app, this is the package.

---

## Installation

```bash
composer require padosoft/laravel-flow
```

Publish the config (optional — the engine works with defaults):

```bash
php artisan vendor:publish --tag=laravel-flow-config
```

That's it. v0.1 has no migrations.

> **Requirements**
> - PHP 8.3+
> - Laravel 12.x or 13.x

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

dd($dryRun->stepResults['simulate']->businessImpact);
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
    'default_storage'        => env('LARAVEL_FLOW_STORAGE', null),       // v0.2
    'audit_trail_enabled'    => env('LARAVEL_FLOW_AUDIT_ENABLED', true), // event emission
    'dry_run_default'        => env('LARAVEL_FLOW_DRY_RUN_DEFAULT', false),
    'step_timeout_seconds'   => (int) env('LARAVEL_FLOW_STEP_TIMEOUT', 300), // v0.2
    'compensation_strategy'  => env('LARAVEL_FLOW_COMPENSATION', 'reverse-order'),
];
```

| Key                       | Default          | Effect                                                                                            |
| ------------------------- | ---------------- | ------------------------------------------------------------------------------------------------- |
| `default_storage`         | `null`           | DB connection used by v0.2 persistence. Inherits app default when `null`.                         |
| `audit_trail_enabled`     | `true`           | When `false`, the engine suppresses every `FlowStep*` / `FlowCompensated` event.                  |
| `dry_run_default`         | `false`          | When `true`, `Flow::execute()` behaves like `dryRun()` — guard rail for staging environments.     |
| `step_timeout_seconds`    | `300`            | Reserved for v0.2 queued workers.                                                                 |
| `compensation_strategy`   | `reverse-order`  | `parallel` reserved for v0.2 — currently falls back to reverse-order.                             |

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

Every box is one PHP class under `src/`. The engine is in-memory and synchronous in v0.1. v0.2 will introduce a `flow_runs` Eloquent model + queued worker that hydrates a definition by name and resumes execution at the failed step.

---

## AI vibe-coding pack

🚀 **Every Padosoft package ships with the same vibe-coding pack** — drop the `.claude/` directory into Claude Code or GitHub Copilot and you get:

- **Skills** under `.claude/skills/` — reviewer-validated playbooks for `copilot-pr-review-loop`, `pre-push-self-review`, `test-count-readme-sync`, and more.
- **Rules** under `.claude/rules/` — coding standards (Laravel 13 defaults, type hints, early return, no debug in commits, code structure, naming conventions, PR workflow).
- **Agents** under `.claude/agents/` — pre-wired sub-agent definitions (`admin-interface-architect`, `playwright-enterprise-tester`).
- **Commands** under `.claude/commands/` — slash-command templates (`/create-job`, `/domain-scaffold`, `/playwright-tester`, `/pagespeed-review`).
- **Instructions** under `.claude/instructions/` — runtime safety guardrails (`testing-safety.md`).

The pack is the same baseline used across all `padosoft/*` repos. It is opt-in: delete `.claude/` if you don't use Claude Code or Copilot — nothing else depends on it.

---

## Testing — Default + Live

The default `phpunit` invocation runs only the offline `Unit` + `Architecture` testsuites and never makes a network call:

```bash
composer install
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
```

The `Live` testsuite is **opt-in** and reserved for v0.2+ scenarios that need a real external dependency (queue worker, webhook receiver). Every Live test self-skips unless `LARAVEL_FLOW_LIVE=1` is set:

```bash
LARAVEL_FLOW_LIVE=1 vendor/bin/phpunit --testsuite Live
```

CI runs Pint (style), PHPStan (level 6), and the Unit + Architecture suites on the full PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 matrix on every push and PR.

---

## Roadmap

| Version | Scope                                                                                                                                                                                                                                                              | Target            |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- |
| v0.1    | In-memory engine, fluent builder, dry-run, reverse-order compensation, four audit events, business-impact field on results, Facade. Architecture test enforces standalone-agnostic.                                                                                  | code complete     |
| v0.2    | Persisted runs (`flow_runs` / `flow_steps` / `flow_audit` tables), queued workers, replay command, parallel compensation strategy, web dashboard.                                                                                                                    | Q3 2026           |
| v0.3    | Approval-gate primitive (a step type that pauses until an external token is presented), webhooks for resume.                                                                                                                                                         | Q4 2026           |
| v1.0    | Stable API, semver guarantee, full migration helpers from Spatie Workflow / Symfony Workflow.                                                                                                                                                                        | 2027              |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). PRs target `main` directly (community repo, no integration branches).

---

## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities privately to `lorenzo.padovani@padosoft.com`.

---

## License

[Apache-2.0](LICENSE).

Built by [Padosoft](https://padosoft.com) — part of the [v4.0 ecosystem](https://github.com/lopadova/AskMyDocs).
