<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use RuntimeException;

final class PayloadRedactorResolution
{
    private const MAX_PROVIDER_DEPTH = 32;

    /**
     * @param  null|callable(CurrentPayloadRedactorProvider): PayloadRedactor  $resolveCurrentRedactor
     */
    public static function current(PayloadRedactor $redactor, ?callable $resolveCurrentRedactor = null): PayloadRedactor
    {
        $seen = [];
        $depth = 0;

        while ($redactor instanceof CurrentPayloadRedactorProvider) {
            $depth++;

            if ($depth > self::MAX_PROVIDER_DEPTH) {
                throw new RuntimeException('Cyclic CurrentPayloadRedactorProvider chain detected.');
            }

            $id = spl_object_id($redactor);

            if (isset($seen[$id])) {
                throw new RuntimeException('Cyclic CurrentPayloadRedactorProvider chain detected.');
            }

            $seen[$id] = true;
            $next = $resolveCurrentRedactor !== null
                ? $resolveCurrentRedactor($redactor)
                : $redactor->currentRedactor();

            if ($next === $redactor) {
                throw new RuntimeException('Cyclic CurrentPayloadRedactorProvider chain detected.');
            }

            $redactor = $next;
        }

        return $redactor;
    }
}
