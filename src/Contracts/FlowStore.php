<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

interface FlowStore
{
    public function runs(): RunRepository;

    public function steps(): StepRunRepository;

    public function audit(): AuditRepository;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(callable $callback): mixed;
}
