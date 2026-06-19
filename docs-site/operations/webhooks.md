---
title: Webhooks
description: Signed webhook outbox delivery.
---

# Webhooks

The webhook outbox persists lifecycle rows for `flow.completed`, `flow.failed`, `flow.paused`, and `flow.resumed`. Delivery is handled by `flow:deliver-webhooks`.

```bash
php artisan flow:deliver-webhooks
```

::: callout tip "Schedule it" icon:clock
When webhooks are enabled, schedule the command at a short interval, such as once per minute. The outbox uses attempt counts and retry scheduling.
:::

## Signature

When `webhook.secret` is set, each request carries an `X-Laravel-Flow-Signature` header with timestamp and HMAC-SHA256 value. Receivers should verify the timestamp window and digest.
