---
title: Connect nodes
description: Ready-made HTTP, transform, condition, delay and batch nodes from laravel-flow-connect.
---

# Connect nodes

The companion package [`padosoft/laravel-flow-connect`](https://github.com/padosoft/laravel-flow-connect) (v1.1+, requires core `^2.6`) registers five graph nodes with the node registry. List them with `php artisan flow:nodes`.

| Node | Does |
| --- | --- |
| `connect.http.request` | Calls an HTTP API through a **named connection**. The graph carries the connection name, never a credential. |
| `connect.transform` | Reshapes data with a declarative path/template mapping. No code, no expressions. |
| `connect.condition` | Takes **one branch**. The other branch is skipped, not run. |
| `connect.delay` | Waits for a duration or until a time, without a worker sleeping. |
| `connect.batch` | Splits a list into fixed-size batches. |

```bash
composer require padosoft/laravel-flow-connect
```

## HTTP requests without secrets in the graph

Base URL, authentication, timeouts and limits live in `config/laravel-flow-connect.php`. A node only names the connection:

```php
new GraphNode('charge', 'connect.http.request', [
    'connection' => 'stripe', 'method' => 'POST', 'path' => 'charges',
    'body' => ['amount' => 1200, 'currency' => 'eur'],
]);
```

::: callout warning "Deny by default" icon:shield
`http.allowed_hosts` is the egress allow-list and an **empty list denies every request**, so the node does nothing until a host opts in. A request goes out only when its host equals the connection's host and is on the list. Hosts that resolve to loopback, private, link-local (cloud metadata), CGNAT or reserved addresses are refused unless the connection sets `allow_private_network`, and redirects are never followed.
:::

`connection`, `method` and `path` are declared `requiresTrusted`. Core's [taint analysis](/best-practices/provenance) therefore refuses to publish a graph that feeds them from an untrusted source such as a model completion. The response (`status`, `ok`, `body`, `headers`) is `Untrusted`.

## Branching and timers

`connect.condition` is built on [per-port branching](/guides/branching): it activates its `true` or `false` port and the nodes behind the other port are skipped. `connect.delay` is built on [engine-resumed timers](/operations/timers): on a queued run the node is stored `paused` and a delayed job resumes it. Schedule the safety net so a lost job cannot leave a delay behind:

```php
// routes/console.php
Schedule::command('flow:resume-due-timers')->everyMinute()->withoutOverlapping();
```

## Reshaping data and batching

```php
new GraphNode('shape', 'connect.transform', ['mapping' => [
    'email' => '$.customer.email',
    'greeting' => 'Hello {{ $.customer.name }}',
    'qty' => ['path' => '$.items.0.qty', 'cast' => 'int', 'default' => 1],
]]);

new GraphNode('chunks', 'connect.batch', ['items' => [1, 2, 3, 4, 5], 'size' => 2]);
// batches: [[1, 2], [3, 4], [5]], count: 3 — pair with flow.foreach
```

The full port tables, the condition operators and the HTTP security model are in the [package README](https://github.com/padosoft/laravel-flow-connect#nodes).
