---
title: Configuration
description: Main laravel-flow configuration knobs.
---

# Configuration

The published `config/laravel-flow.php` file controls persistence, queue locks, approvals, webhooks, audit, dry-run defaults, timeouts, and compensation strategy.

::: tabs
### Persistence

```php
'persistence' => [
    'enabled' => env('LARAVEL_FLOW_PERSISTENCE_ENABLED', false),
    'redaction' => [
        'enabled' => true,
        'keys' => ['password', 'token', 'secret', 'api_key'],
    ],
],
```

### Queue

```php
'queue' => [
    'lock_store' => env('LARAVEL_FLOW_QUEUE_LOCK_STORE'),
    'lock_seconds' => 3600,
    'lock_retry_seconds' => 30,
    'tries' => null,
    'backoff_seconds' => null,
],
```

### Webhook

```php
'webhook' => [
    'enabled' => false,
    'url' => '',
    'secret' => null,
    'max_attempts' => 3,
],
```
:::

## Compensation strategy

The default `reverse-order` strategy is the safest option because many rollback actions depend on undoing the newest side effect first. Use `parallel` only for independent and idempotent compensators.

```php
'compensation_strategy' => 'reverse-order',
'compensation_parallel_driver' => 'process',
```

::: callout warning "Shared locks" icon:lock
Do not use the process-local `array` cache store for approval decisions. Approval resume and reject must serialize decisions across HTTP and queue workers.
:::
