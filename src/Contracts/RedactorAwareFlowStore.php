<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

interface RedactorAwareFlowStore extends FlowStore
{
    public function withPayloadRedactor(PayloadRedactor $redactor): FlowStore;
}
