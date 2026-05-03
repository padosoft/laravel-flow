<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

final class RunFlowJob implements ShouldQueueAfterCommit
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $name,
        public readonly array $input = [],
        public readonly ?FlowExecutionOptions $options = null,
    ) {}

    public function handle(FlowEngine $flow): FlowRun
    {
        return $flow->execute($this->name, $this->input, $this->options);
    }
}
