<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

/**
 * @api
 */
interface PayloadRedactor
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array;
}
