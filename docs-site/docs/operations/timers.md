---
title: Timers
description: Pause a node until a point in time and let the engine resume it, with NodeResult::pausedUntil().
---

# Timers

A node can wait for a point in time and let the **engine** resume it. No worker sleeps, and no external decision is needed.

```php
use Padosoft\LaravelFlow\Node\NodeResult;

public function execute(NodeContext $context): NodeResult
{
    return NodeResult::pausedUntil(
        now()->addHours(2),
        ['out' => $context->inputs['in']],
    );
}
```

`NodeResult::pausedUntil(DateTimeInterface $resumeAt, array $outputs = [])` pauses the node. When `$resumeAt` arrives the node completes as `succeeded` with `$outputs`, and the rest of the graph continues. The handler is **not** run again on resume.

## How it behaves

| Where the node runs | What happens |
| --- | --- |
| **Queued run** | The node is stored as `paused` with `resume_at`. A delayed `ResumeTimerJob` completes it when due. The worker is never blocked. |
| **Synchronous run** | The executor sleeps inline **only** when the wait is at most `executor.max_inline_delay_seconds` (default 5). A longer wait fails the node with a message telling you to run the graph queued. |
| **Dry run** | The node completes immediately. Nothing waits and nothing is written. |
| **Time already past** | The node completes immediately. |

A timer is not an approval gate: it never issues a token, and nothing can resume it early.

## Configuration

```php
'executor' => [
    'max_inline_delay_seconds' => 5,     // longest wait a synchronous run sleeps inline
    'timer_max_job_delay_seconds' => 900, // longest single queue delay (the SQS ceiling)
],
```

A wait longer than `timer_max_job_delay_seconds` hops across several delayed jobs, so a 24-hour timer works on SQS.

## Schedule the safety net

A delayed job normally resumes each timer. Two cases leave a due timer behind: a job that was lost, and a queue driver that cannot delay (`sync`, or a driver without delayed dispatch). `flow:resume-due-timers` resumes every timer that is already due:

```php
// routes/console.php
Schedule::command('flow:resume-due-timers')->everyMinute()->withoutOverlapping();
```

```bash
php artisan flow:resume-due-timers --limit=500   # dispatch a resume job per due timer
php artisan flow:resume-due-timers --sync        # resume in this process
```

::: callout warning "The sync queue driver ignores delays" icon:alert-triangle
On `queue.default=sync` a delayed job runs immediately, before the timer is due, and then stops. The timer stays `paused` until `flow:resume-due-timers` runs. In production use a real queue driver **and** schedule the command.
:::

The command is idempotent. Resuming a timer that was already resumed, or whose run was cancelled, does nothing.

## Cancelling

`Flow::cancel($runId)` terminates a timer-paused node like any other paused node. A resume job that fires afterwards finds the node already `failed` and does nothing.

## Persistence

A timer stores `flow_run_nodes.resume_at` (a nullable `timestampTz`, indexed with `status`). Only a timer writes it, so an approval pause is never confused with one. The migration is `2026_09_28_000002_add_resume_at_to_flow_run_nodes.php`:

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

Without it, graphs with no timer run unchanged, and a graph that uses one fails with a message naming the missing migration. Restart Octane or queue workers after migrating, because the column's presence is checked once per process.

The dashboard read model exposes the value as `StepSummary::$resumeAt`.

## Ready-made delay node

The [`padosoft/laravel-flow-connect`](https://github.com/padosoft/laravel-flow-connect) package ships a `connect.delay` node built on `NodeResult::pausedUntil()`.
