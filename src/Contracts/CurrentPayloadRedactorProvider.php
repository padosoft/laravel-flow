<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

/**
 * Implement on PayloadRedactor decorators that can expose one stable inner
 * redactor for a full repository record write.
 */
interface CurrentPayloadRedactorProvider extends PayloadRedactor
{
    public function currentRedactor(): PayloadRedactor;
}
