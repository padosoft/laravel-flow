---
title: Triggers
description: Start flow runs from cron schedules, Laravel events and signed inbound webhooks with laravel-flow-connect.
---

# Triggers

Core runs flows. The companion package [`padosoft/laravel-flow-connect`](https://github.com/padosoft/laravel-flow-connect) *starts* them from config, with no glue code:

| Trigger | Starts a run when… | Input comes from |
|---|---|---|
| `ScheduleTrigger` | a cron expression matches (Laravel's own scheduler) | the static `input` array in config |
| `EventTrigger` | a host Laravel event is dispatched | an optional `EventInputMapper`, or `[]` |
| `WebhookTrigger` | a signed `POST` arrives on its route | an optional `WebhookInputMapper`, or the JSON body verbatim |

All three implement core's `Padosoft\LaravelFlow\Contracts\FlowTrigger` (`@api`) and start the run through `Flow::dispatch()`.

```bash
composer require padosoft/laravel-flow-connect
php artisan vendor:publish --tag=laravel-flow-connect-config
```

::: callout tip "Failure isolation" icon:shield
A malformed config entry is skipped and logged at boot, never fatal. An event listener never lets an exception escape into the host's `event()` call. A webhook never leaks internal error detail to the caller.
:::

## Schedule

```php
// config/laravel-flow-connect.php
'schedule_triggers' => [
    ['flow' => 'daily-report', 'cron' => '0 6 * * *', 'input' => ['range' => 'yesterday']],
    ['flow' => 'eu-digest', 'cron' => '30 7 * * 1-5', 'timezone' => 'Europe/Rome'],
],
```

Entries run under the usual `php artisan schedule:run` and appear in `schedule:list`. A `fire()` failure is logged and never aborts the other scheduled events.

## Event

```php
'event_triggers' => [
    ['event' => \App\Events\OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => \App\Flow\OrderPlacedMapper::class],
],
```

```php
use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;

final class OrderPlacedMapper implements EventInputMapper
{
    public function map(object $event): array
    {
        return ['order_id' => $event->order->id];
    }
}
```

A mapper that cannot build valid input should throw. The occurrence is then logged and no run is created.

## Inbound webhook

Webhooks are opt-in:

```php
'webhook' => [
    'enabled' => true,
    'triggers' => [
        'order-webhook' => ['flow' => 'fulfill-order', 'secret' => env('ORDER_WEBHOOK_SECRET')],
    ],
],
```

This registers `POST /laravel-flow-connect/webhook/order-webhook`. Callers sign requests with the **same** `X-Laravel-Flow-Signature: t={timestamp},v1={hmac}` scheme core uses for its [outbound webhooks](/operations/webhooks), so one laravel-flow app can trigger another directly.

| Response | When |
|---|---|
| `202` | valid signature, run dispatched |
| `401` | missing/invalid signature, timestamp outside the window, or replay |
| `404` | no valid trigger entry for the slug |
| `422` | body is not a JSON object/array |
| `500` | mapper or dispatch failed; detail is logged, never returned |

::: callout warning "Replay cache" icon:alert-triangle
Replay protection consumes each signature once through an atomic `Cache::add()`. In a multi-server deployment, use a shared cache store such as Redis or the database.
:::

## Custom trigger sources

Any signal source (a queue consumer, a mail poller, an MQTT client) can implement `FlowTrigger` and call `Flow::dispatch()`. See the [Contracts reference](/reference/contracts).
