<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\TestCase;

final class FlowRunTest extends TestCase
{
    public function test_mark_compensated_keeps_legacy_zero_argument_call_while_setting_finished_at(): void
    {
        $run = new FlowRun(
            id: '00000000-0000-4000-8000-000000000001',
            definitionName: 'flow.legacy.compensated',
            dryRun: false,
            startedAt: new DateTimeImmutable('2026-05-02 10:00:00'),
        );

        $now = Carbon::parse('2026-05-02 10:00:05');
        Date::setTestNow($now);

        try {
            $run->markCompensated();
        } finally {
            Date::setTestNow();
        }

        $this->assertSame(FlowRun::STATUS_COMPENSATED, $run->status);
        $this->assertTrue($run->compensated);
        $this->assertInstanceOf(DateTimeImmutable::class, $run->finishedAt);
        $this->assertSame($now->getTimestamp(), $run->finishedAt->getTimestamp());
    }
}
