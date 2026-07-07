<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

final class NotANode
{
    public function irrelevant(): string
    {
        return 'not a node';
    }
}
