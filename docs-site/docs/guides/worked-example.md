---
title: Worked Example
description: Build a promotion workflow end to end.
---

# Worked Example

This example models `promotion.create` for an ecommerce admin panel.

::: steps
1. Validate the request payload.

   ```php
   final class ValidatePromotionInput implements FlowStepHandler
   {
       public function handle(FlowContext $context): FlowStepResult
       {
           $input = $context->input();

           if (($input['discount_pct'] ?? 0) <= 0) {
               return FlowStepResult::failure('discount_pct must be positive');
           }

           return FlowStepResult::success();
       }
   }
   ```

2. Simulate impact.

   ```php
   final class SimulatePromotionImpact implements FlowStepHandler
   {
       public function handle(FlowContext $context): FlowStepResult
       {
           return FlowStepResult::success(
               output: ['eligible_customers' => 1200],
               businessImpact: ['margin_risk_eur' => 4200],
           );
       }
   }
   ```

3. Persist and compensate.

   ```php
   Flow::define('promotion.create')
       ->withInput(['brand', 'discount_pct', 'starts_at', 'ends_at'])
       ->step('validate', ValidatePromotionInput::class)
       ->step('simulate', SimulatePromotionImpact::class)
           ->withDryRun(true)
       ->step('persist', PersistPromotion::class)
           ->compensateWith(ReversePromotion::class)
       ->register();
   ```
:::

::: callout warning "Worked example limit" icon:triangle-alert
This example focuses on orchestration. Production handlers still need authorization, domain validation, transactions where appropriate, and redaction for stored payloads.
:::
