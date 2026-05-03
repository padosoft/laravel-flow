<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use RuntimeException;

final class PayloadRedactorResolution
{
    public static function current(PayloadRedactor $redactor): PayloadRedactor
    {
        $seen = [];

        while ($redactor instanceof CurrentPayloadRedactorProvider) {
            $id = spl_object_id($redactor);

            if (isset($seen[$id])) {
                throw new RuntimeException('Cyclic CurrentPayloadRedactorProvider chain detected.');
            }

            $seen[$id] = true;
            $next = $redactor->currentRedactor();

            if ($next === $redactor) {
                return $redactor;
            }

            $redactor = $next;
        }

        return $redactor;
    }
}
