<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Exceptions;

/**
 * Raised when a persistence-backed operation cannot reach its store — the
 * laravel-flow tables are not migrated, or the persistence connection is
 * unreachable. A subtype of {@see FlowExecutionException} (so existing
 * `catch (FlowExecutionException)` handlers keep working), but distinct so a
 * caller can tell an INFRASTRUCTURE outage (retry with backoff / alert /
 * HTTP 503) apart from an ordinary state conflict (HTTP 409). Raised by the
 * DB-backed mutation seams — `FlowEngine::cancel()`, `replay()`,
 * `redeliverWebhook()` — when their underlying query hits a driver error.
 * {@see ApprovalPersistenceException} is the approval-decision specialization
 * of this same "persistence is down" condition.
 *
 * @api
 */
class PersistenceUnavailableException extends FlowExecutionException {}
