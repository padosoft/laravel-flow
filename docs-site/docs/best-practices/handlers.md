---
title: Handlers
description: Write maintainable flow handlers and compensators.
---

# Handlers

Handlers should be small adapters around domain services. Keep orchestration in the flow definition and domain logic in services that can be tested independently.

::: steps
1. Type dependencies in the constructor.
2. Read input from `FlowContext`.
3. Return `FlowStepResult::success()` or a failure result.
4. Put irreversible side effects behind a compensator where possible.
5. Add business impact when the result matters to operators or previews.
:::

```php
final class CapturePayment implements FlowStepHandler
{
    public function __construct(private PaymentGateway $gateway) {}

    public function handle(FlowContext $context): FlowStepResult
    {
        $charge = $this->gateway->capture($context->input()['order_id']);

        return FlowStepResult::success(output: [
            'charge_id' => $charge->id,
        ]);
    }
}
```
