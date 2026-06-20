---
title: Queues
description: Operate queued laravel-flow dispatch safely.
---

# Queues

`Flow::dispatch($name, $input, $options)` queues a `RunFlowJob` after commit. The job uses cache locks to reduce duplicate execution risk.

```php
Flow::dispatch('order.fulfill', ['order_id' => 123]);
```

::: callout warning "Lock store" icon:lock
Queued workers need a shared atomic lock store. The process-local `array` store is only acceptable for sync queue scenarios and is rejected for approval decisions.
:::

## Retry metadata

`queue.tries` and `queue.backoff_seconds` stamp Laravel-native retry metadata onto the job. Be conservative with whole-run retries until every side effect is idempotent or guarded.
