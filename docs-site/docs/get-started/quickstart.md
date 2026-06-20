---
title: Quickstart
description: Define and run your first laravel-flow workflow.
---

# Quickstart

This example creates a promotion flow with validation, dry-run simulation, persistence, and compensation.

::: steps
1. Create a handler.

   ```php
   use Padosoft\LaravelFlow\FlowContext;
   use Padosoft\LaravelFlow\FlowStepHandler;
   use Padosoft\LaravelFlow\FlowStepResult;

   final class ValidatePromotionInput implements FlowStepHandler
   {
       public function handle(FlowContext $context): FlowStepResult
       {
           return FlowStepResult::success(output: [
               'validated' => true,
           ]);
       }
   }
   ```

2. Create a dry-run-aware simulation handler.

   ```php
   final class SimulatePromotionImpact implements FlowStepHandler
   {
       public function handle(FlowContext $context): FlowStepResult
       {
           return FlowStepResult::success(
               output: ['eligible_customers' => 1200],
               businessImpact: ['estimated_revenue_delta' => -4200],
           );
       }
   }
   ```

3. Register the flow during application boot.

   ```php
   use Padosoft\LaravelFlow\Facades\Flow;

   Flow::define('promotion.create')
       ->withInput(['brand', 'discount_pct', 'starts_at', 'ends_at'])
       ->step('validate', ValidatePromotionInput::class)
       ->step('simulate', SimulatePromotionImpact::class)
           ->withDryRun(true)
       ->step('persist', PersistPromotion::class)
           ->compensateWith(ReversePromotion::class)
       ->register();
   ```

4. Execute or simulate.

   ```php
   $preview = Flow::dryRun('promotion.create', $input);
   $run = Flow::execute('promotion.create', $input);
   ```
:::

::: callout tip "Fast feedback" icon:zap
Run the dry-run path first in controllers and admin actions when a user needs to understand downstream business impact before committing writes.
:::

## Result model

Each `FlowRun` has an id, status, failed step when applicable, compensation metadata, step results, business impact, and timestamps. When persistence is disabled, the run exists only in memory for the current execution.
