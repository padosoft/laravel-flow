---
title: Compensation
description: Roll back completed laravel-flow steps safely.
---

# Compensation

Compensation is saga rollback. When step `N` fails, laravel-flow walks previously completed compensatable steps from `N - 1` back to the first step and calls each `FlowCompensator`.

```php
Flow::define('order.fulfill')
    ->withInput(['order_id'])
    ->step('reserve_stock', ReserveStock::class)
        ->compensateWith(ReleaseStock::class)
    ->step('capture_payment', CapturePayment::class)
        ->compensateWith(RefundPayment::class)
    ->step('ship', CreateShipment::class)
        ->compensateWith(CancelShipment::class)
    ->register();
```

```mermaid
sequenceDiagram
    participant Engine
    participant Stock
    participant Payment
    participant Shipping
    Engine->>Stock: reserve_stock succeeds
    Engine->>Payment: capture_payment succeeds
    Engine->>Shipping: ship fails
    Engine->>Payment: refund payment
    Engine->>Stock: release stock
```

::: callout tip "Compensator design" icon:rotate-ccw
Write compensators to be idempotent. They may be called during failure recovery or manual replay scenarios.
:::
