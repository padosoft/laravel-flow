<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Exceptions;

/**
 * The approval-decision specialization of {@see PersistenceUnavailableException}
 * — raised when an approve/reject/resume path cannot reach the approval store.
 * Still IS-A {@see FlowExecutionException} (transitively), so existing catches
 * keep working; a caller that wants to treat any persistence outage uniformly
 * can catch the {@see PersistenceUnavailableException} parent instead.
 *
 * @api
 */
final class ApprovalPersistenceException extends PersistenceUnavailableException {}
