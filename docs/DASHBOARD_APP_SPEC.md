# Dashboard App Specification — `padosoft-laravel-flow-dashboard`

This document is the durable, self-contained specification for an AI agent (or human team) that will implement the companion dashboard application for `padosoft/laravel-flow`. The dashboard lives in its **own GitHub repository** (`padosoft/padosoft-laravel-flow-dashboard`), not inside this package.

The host package (`padosoft/laravel-flow`) is intentionally headless and exposes only stable read/auth contracts. The dashboard is the operational UI layer.

## Goal

Build a dense, operator-grade web dashboard that surfaces persisted Laravel Flow runs, lets operators inspect step state and audit timelines, replay terminal runs, and approve or reject paused approval gates. The UI is **operational, not marketing**.

## Repository

- **Repo**: `padosoft/padosoft-laravel-flow-dashboard` (separate from this package)
- **Local sibling default**: `../padosoft-laravel-flow-dashboard`
- **License**: MIT (match the package)

## Tech stack

- **Backend**: Laravel 13 application, PHP `^8.3` (matches the package matrix).
- **Frontend**: pick one and stay consistent across the repo:
  - **Option A (preferred)**: Inertia.js + Vue 3 + Vite + TypeScript.
  - **Option B**: Inertia.js + React 18 + Vite + TypeScript.
  - **Option C**: Livewire 3 + Alpine.js (PHP-first, simpler for small teams).
- **Styling**: Tailwind CSS, no UI kit dependency. Custom components only.
- **Test stack**: PHPUnit 12 (backend), Vitest (frontend unit), Vite build, Playwright (E2E).
- **Format/Lint**: Pint (PHP), Prettier + ESLint (frontend).
- **Static analysis**: PHPStan level 6 minimum.

The host package exposes its CLI commands (`flow:approve`, `flow:reject`, `flow:replay`, `flow:deliver-webhooks`, `flow:prune`); the dashboard does not duplicate them, it triggers HTTP endpoints inside the dashboard app that call the public engine API directly.

## Composer setup during development

`composer.json` of the dashboard app must consume the package via a path repository while developing:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../padosoft-laravel-flow",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "padosoft/laravel-flow": "^1.0"
    }
}
```

CI builds resolve from Packagist normally.

## Package contracts the dashboard consumes

The dashboard MUST depend only on the public package surface listed below. Do not import internal Eloquent records (`Padosoft\LaravelFlow\Models\*`) or repositories (`Padosoft\LaravelFlow\Persistence\*`) directly; use the read service. Plain approval tokens are NEVER recoverable from storage; they are only available at issuance time on the immediate run object.

### Read models — `Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel`

```php
$reader = app(FlowDashboardReadModel::class);

// Paginated list
$page = $reader->listRuns(
    new RunFilter(status: 'failed', startedSince: now()->subDay()),
    new Pagination(page: 1, perPage: 25),
);
// $page is PaginatedResult<RunSummary> with ->items (list<RunSummary>),
// ->total, ->page, ->perPage, ->totalPages()

// Run detail with full audit timeline
$detail = $reader->findRun($runId);
// RunDetail{ run, steps, audit, approvals, webhookOutbox, input, output, businessImpact }
// Returns null if the run does not exist.

// Pending approvals queue
$pending = $reader->pendingApprovals(limit: 50);  // list<ApprovalSummary>

// Webhook outbox health
$failed = $reader->failedWebhookOutbox(limit: 50);
$pending = $reader->pendingWebhookOutbox(limit: 50);

// Aggregate KPIs for landing
$kpis = $reader->kpis();
// Kpis{ totalRuns, runningRuns, pausedRuns, failedRuns, compensatedRuns,
//       pendingApprovals, webhookOutboxPending, webhookOutboxFailed }
```

### Authorization hook — `Padosoft\LaravelFlow\Dashboard\Authorization\DashboardActionAuthorizer`

The dashboard MUST bind a custom implementation in its service provider. The default `AllowAllAuthorizer` is for development only.

```php
interface DashboardActionAuthorizer {
    public function canViewRuns(?array $actor): bool;
    public function canViewRunDetail(string $runId, ?array $actor): bool;
    public function canReplayRun(string $runId, ?array $actor): bool;
    public function canApproveByToken(string $tokenHash, ?array $actor): bool;
    public function canRejectByToken(string $tokenHash, ?array $actor): bool;
    public function canViewKpis(?array $actor): bool;
}
```

Every dashboard controller call to a destructive engine API MUST first call the matching `can*` method and return 403 on `false`.

### Destructive engine APIs

```php
use Padosoft\LaravelFlow\Facades\Flow;

// Resume a paused approval gate (operator must supply the plain token they
// received out-of-band when the gate fired)
Flow::resume($plainToken, payload: ['decision' => 'approve'], actor: ['user_id' => $authId]);

// Reject a paused approval gate
Flow::reject($plainToken, payload: ['decision' => 'reject', 'reason' => $note], actor: [...]);

// Replay a terminal persisted run (creates a new linked run; original
// is not mutated)
$replayedRun = Flow::replay($originalRunId);
```

Plain approval tokens are never stored. Operators who can act on a gate must already have access to the token (e.g. delivered through email/Slack via the webhook). The dashboard does not bypass token verification.

## Functional scope

### Pages / routes

| Route | Purpose |
| --- | --- |
| `/flow` | Landing — KPI tiles + recent runs + pending approvals queue + failed outbox banner |
| `/flow/runs` | Filterable, paginated run list |
| `/flow/runs/{id}` | Run detail (steps, audit, approvals, outbox), replay button, raw JSON drawer |
| `/flow/approvals` | Pending approval queue with resume/reject controls |
| `/flow/outbox` | Webhook outbox view: failed + pending + delivered (filter) |

### KPI tile set on landing

Render six tiles, color-only-as-secondary signal (do not encode meaning solely with color):

- Total runs
- Running runs
- Paused runs (with link to `/flow/approvals`)
- Failed runs
- Compensated runs
- Webhook outbox failed (link to `/flow/outbox`)

### Filters

- Run list: definition name, status, correlation id, started since/until.
- Approvals: pending only by default, toggle to show all (approved/rejected/expired).
- Outbox: status (delivered, pending, failed), event type.

### Run detail view

- Header: definition, run id, status badge, dry-run badge if applicable, correlation id, idempotency key, replayed-from link if present.
- Steps timeline: sequence, name, handler, status, duration, error class+message, started/finished timestamps.
- Audit timeline: append-only event ordered by `occurred_at`.
- Approvals subsection: per-step approvals with status and decision metadata (no plain tokens).
- Webhook outbox subsection: rows scoped to this run.
- Action buttons (gated by authorizer):
  - **Replay** — for terminal runs (succeeded/failed/compensated/aborted).
  - **Approve / Reject** — only when run is paused at an approval gate AND operator has provided plain token (input field).
- JSON drawer: raw input/output/business_impact (already redacted by persistence — never display unredacted).

## UI/UX guardrails

These are non-negotiable. Audit reviewers will flag violations.

- Operational UI, not landing page. No hero illustrations, no marketing copy.
- **No nested cards.** Use one container level only; rely on tables, drawers, modals, toasts.
- **Border radius ≤ 8px** everywhere.
- Typography: monospace for IDs and JSON; sans-serif for labels.
- **No secrets in JSON or UI.** Persistence already redacts; the UI never adds back. Show `[redacted]` placeholder strings as-is.
- **Color is never the only signal.** Status badges include text labels (`failed`, `paused`).
- Loading: explicit spinners or skeletons; no layout shift.
- Empty states: concrete `Try X` action or filter-clear button.
- Error states: redact, suggest a next step (`refresh`, `clear filter`, `check logs`).
- Asynchronous actions show feedback (toast or row-level state) and disable the button while in flight.
- Destructive actions (replay, reject) require confirmation dialogs.
- Mobile: a single tablet breakpoint; the tool is desktop-first.

## Security guardrails

- All routes require authentication (Laravel `auth` middleware).
- Bind a custom `DashboardActionAuthorizer` that implements your RBAC; do not ship `AllowAllAuthorizer` to production.
- CSRF protection on all mutating routes.
- Rate limit replay/approve/reject endpoints (e.g. 10/min per user).
- Audit dashboard actions in your application audit log (separate from `flow_audit`).
- Never log plain tokens, even in development.
- Health-check endpoint must NOT expose run IDs or counts.

## Testing requirements

### Backend (PHPUnit)

- Authorizer enforcement: every route calls the right `can*` method; 403 on false.
- Read model integration: returns DTOs of expected shape (use the package's read DTOs as source of truth).
- Replay/Approve/Reject controllers: invoke engine API on success; preserve actor metadata.

### Frontend unit (Vitest)

- KPI tile rendering with various counts.
- Run-list filter form serialization/deserialization.
- Status badge labels and accessibility (aria-label includes textual status).

### Vite build

- Production build must succeed without warnings; bundle size budget recommended (< 250 KB JS gzipped per entry).

### Playwright E2E

Required scenarios (`@e2e`):

1. **Run list — filter and paginate**: filter by `status=failed`, paginate, verify total and current page.
2. **Run detail**: navigate from list, verify steps and audit timeline render in order; raw JSON drawer opens.
3. **Replay**: from a terminal run, click Replay → confirm dialog → success toast → new run appears in list with `replayedFromRunId` linking back.
4. **Approval resume**: paste plain token + payload → confirm → status badge transitions and downstream steps complete.
5. **Approval reject**: similar flow producing a `failed` run with compensation visible.
6. **Failed compensation**: a run with persisted compensation failure is visibly distinguishable in list and detail; compensation status is shown.
7. **Webhook outbox failures**: failed rows visible, retry information accurate.

Playwright must run against a seeded SQLite or test MySQL with realistic fixture data covering each scenario.

## CI gates for the dashboard repo

`.github/workflows/ci.yml` matrix:

- PHP 8.3 + 8.4 with PHPUnit + PHPStan + Pint.
- Node LTS with Vitest + Vite build.
- Playwright job that boots the Laravel app, runs migrations, seeds fixtures, runs the E2E suite.

All jobs must pass before merge. CI must run on PRs targeting `main` and `task/**`, plus pushes to `main` (mirror the host package convention).

## Branch and PR workflow

Same convention as the host package:

- Macro branches under `task/<macro>` (e.g. `task/dashboard-foundations`, `task/dashboard-runs-pages`, `task/dashboard-approvals`, `task/dashboard-replay`, `task/dashboard-release`).
- Subtask branches PR into the macro branch.
- Macro branches PR into `main`.
- Every PR requests GitHub Copilot Code Review; merge only after CI green AND no must-fix Copilot comments.

## Local development

```bash
git clone git@github.com:padosoft/padosoft-laravel-flow-dashboard.git
cd padosoft-laravel-flow-dashboard
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate              # also runs vendor:publish for laravel-flow migrations
npm run dev &
php artisan serve
```

The dashboard `database/migrations` directory must publish the package migrations on first install via `php artisan vendor:publish --tag=laravel-flow-migrations` so the package tables exist.

## Suggested phased delivery

| Phase | Macro branch | Scope |
| --- | --- | --- |
| 1 | `task/dashboard-foundations` | Laravel 13 scaffold, frontend stack, auth middleware, authorizer binding, KPI tile component, layout shell |
| 2 | `task/dashboard-runs-pages` | Run list with filters/pagination + run detail with steps/audit/approvals subsections |
| 3 | `task/dashboard-approvals` | Pending approvals queue + resume/reject flow with confirmation modals |
| 4 | `task/dashboard-replay-and-outbox` | Replay action + webhook outbox view |
| 5 | `task/dashboard-release` | E2E Playwright suite, polish, README, v1.0.0 tag |

Each macro merges into `main` only after its full Copilot/CI loop passes.

## Out of scope

- Editing flow definitions from the UI (definitions are code).
- Triggering arbitrary new runs from the UI (use queue/HTTP entry points).
- Multi-tenant management (handled by host RBAC).
- Webhook retry policy editor (configure via package config).
- Inline secret display (always redacted).

## When the dashboard is "done"

- All Playwright scenarios listed above pass on a clean repo with a published v1.0.0 of `padosoft/laravel-flow`.
- README includes screenshots, install instructions, and links the host package.
- A `v1.0.0` tag is created on `main` and a GitHub release published.
