<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Jobs;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use RuntimeException;

final class RunFlowJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $name,
        public readonly array $input = [],
        public readonly ?FlowExecutionOptions $options = null,
        public readonly ?string $dispatchId = null,
        public readonly ?string $lockStore = null,
        public readonly int $lockSeconds = 3600,
        public readonly int $lockRetrySeconds = 30,
    ) {}

    public function handle(FlowEngine $flow, CacheFactory $cache, ConfigRepository $config): ?FlowRun
    {
        $repository = $cache->store($this->lockStore);
        $store = $repository->getStore();

        if ($store instanceof ArrayStore && ! $this->allowsProcessLocalLocks($config)) {
            throw new RuntimeException('Laravel Flow queued execution requires a shared cache lock store; the array store is process-local.');
        }

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Laravel Flow queued execution requires a cache store that supports atomic locks.');
        }

        if ($repository->get($this->completionKey()) === true) {
            return null;
        }

        $lock = $store->lock($this->lockKey(), $this->lockSeconds());

        if (! $lock->get()) {
            // InteractsWithQueue marks the underlying Laravel job as released;
            // CallQueuedHandler will not delete a released job.
            $this->release($this->lockRetrySeconds());

            return null;
        }

        try {
            $run = $flow->execute($this->name, $this->input, $this->options);

            if ($repository->put($this->completionKey(), true, $this->lockSeconds()) !== true) {
                $exception = new RuntimeException('Laravel Flow queued execution could not record the dispatch completion marker.');

                if ($this->job !== null) {
                    $this->fail($exception);

                    return $run;
                }

                throw $exception;
            }

            return $run;
        } finally {
            $lock->release();
        }
    }

    public function lockKey(): string
    {
        return 'laravel-flow:run:'.$this->dispatchId();
    }

    public function completionKey(): string
    {
        return $this->lockKey().':completed';
    }

    private function dispatchId(): string
    {
        return $this->dispatchId ?? sha1($this->name.'|'.serialize($this->input).'|'.serialize($this->options));
    }

    private function lockSeconds(): int
    {
        return max(1, $this->lockSeconds);
    }

    private function lockRetrySeconds(): int
    {
        return max(1, min($this->lockSeconds(), $this->lockRetrySeconds));
    }

    private function allowsProcessLocalLocks(ConfigRepository $config): bool
    {
        $connection = $config->get('queue.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return $config->get('queue.connections.'.$connection.'.driver') === 'sync';
    }
}
