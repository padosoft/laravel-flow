<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

final class RepositoryResolvingHandler implements FlowStepHandler
{
    public function __construct(
        private readonly RunRepository $runs,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        $this->runs->find('00000000-0000-4000-8000-999999999999');

        return FlowStepResult::success(['repository_resolved' => true]);
    }
}
