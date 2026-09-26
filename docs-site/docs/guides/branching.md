---
title: Branching
description: Take one branch of a graph and skip the rest with NodeResult::branch().
---

# Branching

A node normally hands a value to every wire that leaves it. Branching lets a node choose **which output ports are live**. The nodes behind the ports it did not choose are skipped instead of run.

```php
use Padosoft\LaravelFlow\Node\NodeResult;

public function execute(NodeContext $context): NodeResult
{
    $port = $context->inputs['amount'] >= 1000 ? 'review' : 'auto';

    return NodeResult::branch([$port => $context->inputs['order']], [$port]);
}
```

`NodeResult::branch(array $outputs, array $activePorts)` behaves like `success()` and additionally activates only the listed ports. Every wire that leaves an inactive port is *dead*.

## What a dead wire does

| Situation | Result |
| --- | --- |
| Every incoming wire of a node is dead | The node is **skipped**. It never runs and is recorded as `skipped`. |
| The skip cascades | Everything that depended only on a skipped node is skipped in the same pass. |
| A node has at least one live incoming wire | It **runs**. The dead wire's input is simply absent. |
| A predecessor failed | The node is **blocked**, exactly as before. Failure wins over a dead wire. |

A run with skipped branches finishes `succeeded`. A skipped node is neutral in the run roll-up, and the branch not taken is not an error.

::: callout warning "Joins after a branch" icon:alert-triangle
A node that receives both a taken and a not-taken branch still runs, with the dead input missing. If that input is a **required** port, the node fails with `invalid_input`. Join branches with `flow.merge` (its `items` port is optional and accepts many wires) or declare the joining ports optional.
:::

## Rules

- **Opt-in.** A node that merely omits an optional output is *not* branching. Its downstream nodes still run, exactly as before 2.6.
- **Declare your ports.** Activating an output port the node never declared fails the node, so a typo cannot silently kill a branch.
- **Inactive outputs are dropped.** Whatever you return for a port you did not activate is discarded, so persisted rows and downstream routing agree.
- **Never cached.** A `#[Cacheable]` node that returns a branch result is not cached, because a cache hit cannot carry which ports were activated.
- **Dry runs branch too.** `Flow::dryRunGraph()` executes the node, so the plan shows the real path. `DryRunPlanner` (the structural cost estimate) still lists every branch.

## Persistence

A branching node stores the ports it activated in `flow_run_nodes.active_ports` (a branch-skipped node stores `[]`). Ordinary nodes leave the column `NULL`. The column comes from the migration `2026_09_28_000001_add_active_ports_to_flow_run_nodes.php`:

```bash
php artisan vendor:publish --tag=laravel-flow-migrations
php artisan migrate
```

The migration is only needed once a graph actually branches. Without it, ordinary graphs run unchanged, and a branching graph fails with a message telling you to run it.

The dashboard read model exposes the value as `StepSummary::$activePorts`, so a run view can grey out the branch that was not taken.

## Ready-made condition node

The [`padosoft/laravel-flow-connect`](https://github.com/padosoft/laravel-flow-connect) package ships a `connect.condition` node built on `NodeResult::branch()`. You only need to write your own branching node when the decision is domain-specific.
