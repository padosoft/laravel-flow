<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowDefinitionBuilder;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Facade exposing {@see FlowEngine} as `Flow::define()` / `Flow::execute()`
 * / `Flow::dryRun()` / `Flow::definitions()`.
 *
 * @method static FlowDefinitionBuilder define(string $name)
 * @method static FlowRun execute(string $name, array<string, mixed> $input)
 * @method static FlowRun dryRun(string $name, array<string, mixed> $input)
 * @method static array<string, FlowDefinition> definitions()
 * @method static FlowDefinition definition(string $name)
 * @method static void registerDefinition(FlowDefinition $definition)
 *
 * @see FlowEngine
 */
final class Flow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FlowEngine::class;
    }
}
