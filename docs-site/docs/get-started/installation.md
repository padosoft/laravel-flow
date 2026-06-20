---
title: Installation
description: Install padosoft/laravel-flow in a Laravel 13 application.
---

# Installation

Install the package with Composer:

```bash
composer require padosoft/laravel-flow
```

The package auto-discovers `Padosoft\LaravelFlow\LaravelFlowServiceProvider` and the `Flow` facade through Laravel package discovery.

::: steps
1. Publish configuration when you need to change defaults.

   ```bash
   php artisan vendor:publish --tag=laravel-flow-config
   ```

2. Publish migrations when persistence, approvals, webhooks, replay, or dashboard read models are required.

   ```bash
   php artisan vendor:publish --tag=laravel-flow-migrations
   php artisan migrate
   ```

3. Keep persistence disabled until you want durable telemetry.

   ```php
   'persistence' => [
       'enabled' => env('LARAVEL_FLOW_PERSISTENCE_ENABLED', false),
   ],
   ```
:::

::: callout warning "Runtime baseline" icon:triangle-alert
The package targets PHP `^8.3` and Laravel 13 components. Do not install it into older Laravel applications without checking dependency compatibility first.
:::

## Optional systems

Persistence uses the app database. Queue dispatch uses Laravel queue workers. Approval resume and reject require a shared atomic cache lock store such as Redis, Memcached, database, or DynamoDB. Webhook delivery uses scheduled or manually invoked Artisan commands.
