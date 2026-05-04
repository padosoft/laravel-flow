<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

interface RedactorAwareApprovalRepository extends ApprovalRepository
{
    public function withPayloadRedactor(PayloadRedactor $redactor): ApprovalRepository;
}
