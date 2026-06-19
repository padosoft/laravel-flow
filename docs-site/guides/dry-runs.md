---
title: Dry Runs
description: Model no-write simulations with laravel-flow.
---

# Dry Runs

Dry-run is a first-class execution mode. The engine invokes steps marked with `withDryRun(true)` so they can project output and business impact. Other steps are skipped and represented as dry-run skipped results.

```php
Flow::define('discount.apply')
    ->withInput(['cart_id', 'coupon'])
    ->step('validate', ValidateCoupon::class)
    ->step('simulate', SimulateDiscount::class)
        ->withDryRun(true)
    ->step('commit', ApplyDiscount::class)
        ->compensateWith(RemoveDiscount::class)
    ->register();

$preview = Flow::dryRun('discount.apply', $input);
```

::: callout warning "No durable telemetry" icon:database
Dry-runs never write run, step, or audit rows, even when persistence and audit are enabled. Use the returned `FlowRun` if a UI needs to render the preview.
:::

## Projection formula

KaTeX is useful when describing business projections:

$$
\Delta revenue = projected\ gross - projected\ discount - projected\ refund\ risk
$$

Keep projection handlers deterministic where possible so the preview is explainable and repeatable.
