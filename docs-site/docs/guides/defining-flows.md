---
title: Defining Flows
description: Register fluent laravel-flow definitions.
---

# Defining Flows

A flow definition names required input keys and an ordered list of steps. Steps reference class names, not closures, so handlers survive queue serialization, support Laravel dependency injection, and produce useful stack traces.

```php
Flow::define('customer.onboard')
    ->withInput(['customer_id', 'plan'])
    ->step('validate', ValidateCustomer::class)
    ->step('provision', ProvisionWorkspace::class)
        ->compensateWith(DeleteWorkspace::class)
    ->step('notify', SendWelcomeEmail::class)
    ->register();
```

::: callout info "Naming" icon:tag
Use stable names such as `customer.onboard` or `promotion.create`. Persisted runs and replay diagnostics are easier to reason about when names do not drift.
:::

## Input contract

`withInput()` checks that required keys are present before the first step runs. Missing keys throw `FlowInputException`; validation inside handlers should still check domain-specific constraints.

## Step names

Step names become operational identifiers in run history, audit rows, dashboard DTOs, and replay drift warnings. Treat them as part of the app contract once persistence is enabled.
