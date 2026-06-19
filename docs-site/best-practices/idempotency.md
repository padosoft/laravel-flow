---
title: Idempotency
description: Use correlation and idempotency keys safely.
---

# Idempotency

Idempotency prevents repeated delivery from creating duplicated business effects. laravel-flow exposes execution options for correlation and idempotency metadata, and persisted successful step output can be reused during guarded scenarios.

::: callout tip "Choose stable keys" icon:key
Use a domain identifier, such as an order id plus operation name, instead of a random UUID when the goal is deduplication.
:::

```php
use Padosoft\LaravelFlow\FlowExecutionOptions;

$options = new FlowExecutionOptions(
    correlationKey: 'order:123',
    idempotencyKey: 'order:123:fulfill',
);

$run = Flow::execute('order.fulfill', $input, $options);
```

## Handler rule

External calls should still use provider-level idempotency keys where supported. Flow-level idempotency does not automatically make a vendor API call idempotent.
