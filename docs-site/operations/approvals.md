---
title: Approvals
description: Approval gate operations.
---

# Approvals

An approval gate pauses a persisted run, emits and persists `FlowPaused`, and issues a pending approval record when persistence is enabled.

```php
Flow::define('invoice.pay')
    ->withInput(['invoice_id'])
    ->step('validate', ValidateInvoice::class)
    ->approvalGate('finance_approval')
    ->step('pay', PayInvoice::class)
        ->compensateWith(ReverseInvoicePayment::class)
    ->register();
```

::: tabs
### Resume

```php
$run = Flow::resume($token, ['approved_reason' => 'Budget owner approved'], $actor);
```

### Reject

```php
$run = Flow::reject($token, ['reason' => 'Budget exceeded'], $actor);
```
:::

## CLI decisions

Use `flow:approve` and `flow:reject` for operator decisions from the console or automation.
