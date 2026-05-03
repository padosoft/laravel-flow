<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Jobs;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use RuntimeException;

final class RunFlowJob implements ShouldQueueAfterCommit
{
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
    ) {}

    public function handle(FlowEngine $flow, CacheFactory $cache): ?FlowRun
    {
        $store = $cache->store($this->lockStore)->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Laravel Flow queued execution requires a cache store that supports atomic locks.');
        }

        $lock = $store->lock($this->lockKey(), $this->lockSeconds());

        if (! $lock->get()) {
            return null;
        }

        try {
            return $flow->execute($this->name, $this->input, $this->options);
        } finally {
            $lock->release();
        }
    }

    public function lockKey(): string
    {
        return 'laravel-flow:run:'.$this->dispatchId();
    }

    private function dispatchId(): string
    {
        return $this->dispatchId ?? sha1($this->name.'|'.serialize($this->input).'|'.serialize($this->options));
    }

    private function lockSeconds(): int
    {
        return max(1, $this->lockSeconds);
    }
}
