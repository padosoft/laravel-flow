<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

interface RedactorAwareApprovalRepository extends ApprovalDecisionRepository, ApprovalRepository
{
    public function withPayloadRedactor(PayloadRedactor $redactor): self;
}
