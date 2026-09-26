<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\TimerRepository;
use Padosoft\LaravelFlow\Executor\Jobs\ResumeTimerJob;
use Throwable;

/**
 * Safety net for timer nodes (`NodeResult::pausedUntil()`): dispatches a resume
 * for every timer that is already due. A delayed job normally resumes each
 * timer on its own; this command covers a lost job and a queue driver that
 * cannot delay (`sync`, or a driver without delayed dispatch). It is idempotent
 * — resuming an already-resumed timer is a no-op — so scheduling it every
 * minute is safe.
 *
 * @internal
 */
final class ResumeDueTimersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:resume-due-timers
        {--limit=500 : Maximum number of due timers to resume in one run}
        {--sync : Resume in this process instead of dispatching queue jobs}';

    /**
     * @var string
     */
    protected $description = 'Resume Laravel Flow timer nodes (delay/timer) that are due.';

    public function handle(ConfigRepository $config, BusDispatcher $bus): int
    {
        if (! (bool) $config->get('laravel-flow.persistence.enabled', false)) {
            $this->error('Enable laravel-flow.persistence.enabled before resuming timers: timers are persisted node state.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false) {
            $this->error('Set --limit to a positive integer.');

            return self::FAILURE;
        }

        $store = $this->laravel->make(FlowStore::class);
        $timers = $store->runNodes();

        if (! $timers instanceof TimerRepository) {
            $this->error('The configured run-node repository does not implement Padosoft\LaravelFlow\Contracts\TimerRepository, so timers cannot be resumed.');

            return self::FAILURE;
        }

        try {
            $due = $timers->dueTimers(Date::now(), $limit);
        } catch (Throwable) {
            $this->error('Laravel Flow could not read the due timers. Publish and run the v2.6 migrations, and check the persistence connection.');

            return self::FAILURE;
        }

        $queue = $config->get('laravel-flow.executor.queue');
        $queue = is_string($queue) && $queue !== '' ? $queue : null;

        foreach ($due as $timer) {
            $job = new ResumeTimerJob($timer['run_id'], $timer['node_id'], $queue);

            if ((bool) $this->option('sync')) {
                $bus->dispatchSync($job);
            } else {
                $bus->dispatch($job);
            }
        }

        $this->info(sprintf('%d due timer(s) %s.', count($due), (bool) $this->option('sync') ? 'resumed' : 'dispatched'));

        return self::SUCCESS;
    }
}
