<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\TimerResumer;
use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * Resumes one timer node ({@see NodeResult::pausedUntil()}) when it falls due.
 * {@see NodeJob} dispatches it with a delay right after {@see NodeExecutor}
 * paused the node; `flow:resume-due-timers` dispatches it for anything a lost
 * job or a non-delaying queue driver left behind.
 *
 * Every path is idempotent — {@see TimerResumer} no-ops for a duplicate, a
 * cancelled run or a node that is no longer a paused timer. A job that runs
 * BEFORE the timer is due (clock skew, a capped hop) re-dispatches itself with
 * the remaining delay, except on the `sync` driver, which cannot delay: there
 * the job stops and the sweeper resumes the timer once it is due.
 *
 * @internal
 */
final class ResumeTimerJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $runId,
        public string $nodeId,
        ?string $queue = null,
    ) {
        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(TimerResumer $resumer, BusDispatcher $bus, ConfigRepository $config): void
    {
        $outcome = $resumer->resume($this->runId, $this->nodeId);

        if (! $outcome->isNotDue() || $outcome->dueAt === null || $this->runsOnSyncDriver($config)) {
            return;
        }

        $remaining = max(1, $outcome->dueAt->getTimestamp() - Date::now()->getTimestamp());
        $cap = max(1, (int) $config->get('laravel-flow.executor.timer_max_job_delay_seconds', 900));

        $bus->dispatch((new self($this->runId, $this->nodeId, $this->queue))->delay(min($remaining, $cap)));
    }

    private function runsOnSyncDriver(ConfigRepository $config): bool
    {
        $connection = $this->job?->getConnectionName();

        if (! is_string($connection) || $connection === '') {
            $connection = $config->get('queue.default');
        }

        return is_string($connection) && $connection !== ''
            && $config->get('queue.connections.'.$connection.'.driver') === 'sync';
    }
}
