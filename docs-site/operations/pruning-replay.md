---
title: Pruning and Replay
description: Retention pruning and terminal-run replay.
---

# Pruning and Replay

Use pruning to bound persisted telemetry growth and replay to create a new run from terminal persisted input.

::: tabs
### Prune

```bash
php artisan flow:prune --days=90
```

`flow:prune` keeps pending and running rows intact and is the supported retention deletion path.

### Replay

```bash
php artisan flow:replay 018f4b19-8d4f-7b7e-a0e6-4d6a5b6d8e70
```

Replay creates a new persisted run linked through `replayed_from_run_id` and warns when the registered definition drifted from stored step metadata.
:::
