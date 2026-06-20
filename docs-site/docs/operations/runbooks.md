---
title: Runbooks
description: Operational runbooks for laravel-flow.
---

# Runbooks

## Stuck approval

::: steps
1. Confirm the run is still `paused`.
2. Confirm the approval record is pending and not expired.
3. Confirm `queue.lock_store` points to a shared atomic cache store.
4. Retry `Flow::resume()` or `flow:approve` with a valid token.
5. If a previous resume reached downstream steps, inspect persisted successful step rows before retrying.
:::

## Failed webhook delivery

::: steps
1. Check `flow_webhook_outbox.status`, attempts, and next retry time.
2. Confirm `webhook.url` is reachable from the scheduler or worker.
3. Confirm the receiver accepts `X-Laravel-Flow-Signature`.
4. Run `php artisan flow:deliver-webhooks` manually after fixing connectivity.
:::

## Growing audit table

Schedule `flow:prune` with a retention window that matches compliance needs.
