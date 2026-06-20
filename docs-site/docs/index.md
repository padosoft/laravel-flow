---
title: Overview
description: DX-first workflow, saga, compensation, dry-run, and audit engine for Laravel.
---

# laravel-flow

`padosoft/laravel-flow` is a DX-first workflow, saga, compensation, dry-run, and audit engine for Laravel applications. It keeps orchestration inside the Laravel app you already operate while adding a fluent definition API, container-resolved handlers, reverse-order compensation, dry-run projections, opt-in persistence, approvals, signed webhook outbox delivery, and dashboard read contracts.

::: callout info "Project metadata" icon:info
Package: `padosoft/laravel-flow`. Author: Lorenzo Padovani / Padosoft. License: Apache-2.0. Primary runtime: PHP 8.3 or newer with Laravel 13 components.
:::

::: grids
::: grid
::: card "Define" icon:workflow
Register readable business flows with named inputs and ordered class-based steps.
:::
:::
::: grid
::: card "Simulate" icon:flask-conical
Run `Flow::dryRun()` so dry-run-aware handlers can project impact without persistence writes.
:::
:::
::: grid
::: card "Recover" icon:rotate-ccw
Attach compensators per step and let failures walk completed steps backwards by default.
:::
:::
::: grid
::: card "Operate" icon:gauge
Persist runs, steps, audit rows, approvals, webhook outbox rows, and dashboard DTOs when needed.
:::
:::
:::

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

$run = Flow::execute('promotion.create', $input);
$preview = Flow::dryRun('promotion.create', $input);
```

## When to use it

Use laravel-flow when one request or job must coordinate multiple business steps, some of those steps can fail after earlier side effects, and operators need an auditable result. It is intentionally smaller than an external workflow service: the default engine is synchronous and in-memory, while database persistence and queue dispatch are opt-in.

::: tabs
### Good fit

- Order, promotion, billing, approval, onboarding, or fulfillment flows.
- Workflows where dry-run projection is a product feature.
- Laravel teams that want container DI, events, queues, and Eloquent-compatible persistence.

### Poor fit

- Cross-language workflows that need a dedicated workflow cluster.
- Multi-region managed execution history.
- Long-running distributed orchestration where Temporal or AWS Step Functions is the operating model.
:::

## Documentation map

Start with the quickstart, then read the Guides section for daily use. The Concetti & Teoria section explains the model in Italian and includes motivation, theory, design, data contract, ADRs, a worked example, and limits. Operations covers queues, approvals, webhooks, pruning, replay, and runbooks. Reference lists CLI commands, API classes, contracts, events, and configuration keys.
