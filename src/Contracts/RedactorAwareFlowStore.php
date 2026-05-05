<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

/**
 * Implement on FlowStore decorators that can accept the engine-frozen
 * redactor while preserving transaction and re-entrant repository semantics.
 *
 * @api
 */
interface RedactorAwareFlowStore extends FlowStore
{
    public function withPayloadRedactor(PayloadRedactor $redactor): FlowStore;
}
