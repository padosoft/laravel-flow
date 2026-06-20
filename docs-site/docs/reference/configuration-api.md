---
title: Configuration API
description: Configuration key reference.
---

# Configuration API

| Key | Default | Notes |
| --- | --- | --- |
| `persistence.enabled` | `false` | Enables database persistence for non-dry-run executions. |
| `persistence.redaction.enabled` | `true` | Redacts configured keys before JSON payload storage. |
| `persistence.retention.days` | `null` | Default retention window for `flow:prune`. |
| `queue.lock_store` | `null` | Shared cache store for queue and approval locks. |
| `queue.lock_seconds` | `3600` | Lock TTL for queued runs and approval decisions. |
| `queue.tries` | `null` | Optional Laravel job attempts metadata. |
| `queue.backoff_seconds` | `null` | Optional Laravel job backoff metadata. |
| `approval.token_ttl_minutes` | `1440` | Expiry window for one-time approval tokens. |
| `webhook.enabled` | `false` | Enables lifecycle webhook outbox delivery. |
| `webhook.url` | `''` | Receiver URL. |
| `webhook.secret` | `null` | HMAC signing secret. |
| `audit_trail_enabled` | `true` | Enables events and persisted audit rows. |
| `dry_run_default` | `false` | Makes `Flow::execute()` behave as dry-run. |
| `step_timeout_seconds` | `300` | Reserved for future per-step queue execution. |
| `compensation_strategy` | `reverse-order` | `reverse-order` or `parallel`. |
| `compensation_parallel_driver` | `process` | Laravel Concurrency driver for parallel compensation. |
