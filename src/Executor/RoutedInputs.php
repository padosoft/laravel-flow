<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;

/**
 * Result of {@see InputRouter::route()}: the validated input port map ready to
 * hand a node handler, plus whether routing+validation succeeded. On failure
 * `$valid` is false and `$violation` carries the validation error so the
 * executor can mark the node `invalid_input` WITHOUT calling the handler.
 *
 * @api
 */
final readonly class RoutedInputs
{
    /**
     * @param  array<string, mixed>  $inputs  validated inputs keyed by port key (empty when invalid)
     */
    public function __construct(
        public array $inputs,
        public bool $valid,
        public ?NodeInputValidationException $violation = null,
    ) {}
}
