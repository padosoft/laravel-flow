# laravel-flow

DX-first **workflow engine** for Laravel with native dry-run, compensation chain, approval gate, and business impact tracking.

## Features

- **Dry-run nativo**: simulate workflow execution without writing to DB
- **Compensation chain**: saga-style rollback ordered reverse on failure
- **Approval gate**: human-in-the-loop step type as first-class citizen
- **Business impact tracker**: integrated metrics for write workflows
- **Audit trail**: every step logged immutably
- **Replay**: re-execute past flow runs deterministically

## Installation

```bash
composer require padosoft/laravel-flow
php artisan migrate
```

## Quick start

```php
use Padosoft\LaravelFlow\Facades\Flow;

Flow::define('promotion.create')
    ->withInput(['brand', 'discount_pct', 'starts_at', 'ends_at'])
    ->step('validate', ValidatePromotionInput::class)
    ->step('simulate', SimulatePromotionImpact::class)
        ->withDryRun(true)
    ->step('approval', ApprovalGate::class)
        ->whenRiskLevel('medium')
    ->step('persist', PersistPromotionDraft::class)
        ->compensateWith(DeletePromotionDraft::class)
    ->step('audit', LogAuditEvent::class)
    ->register();
```

## Documentation

See [docs/](./docs/) for full API reference.

## License

Apache-2.0 — see [LICENSE](./LICENSE).

## Status

🚧 Pre-release. v0.1.0 expected June 2026.
