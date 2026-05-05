<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Jobs;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Queue\QueueRetryPolicy;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class RunFlowJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    /**
     * @var array<string, mixed>
     */
    public array $input = [];

    public ?FlowExecutionOptions $options = null;

    public ?string $dispatchId = null;

    public ?string $lockStore = null;

    public int $lockSeconds = 3600;

    public int $lockRetrySeconds = 30;

    public ?int $tries = null;

    /**
     * @var int|list<int>|null
     */
    public int|array|null $backoffSeconds = null;

    /**
     * @param  array<string, mixed>  $input
     * @param  int|list<int>|null  $backoffSeconds
     */
    public function __construct(
        public string $name,
        array $input = [],
        ?FlowExecutionOptions $options = null,
        ?string $dispatchId = null,
        ?string $lockStore = null,
        int $lockSeconds = 3600,
        int $lockRetrySeconds = 30,
        ?int $tries = null,
        int|array|null $backoffSeconds = null,
    ) {
        $this->input = $input;
        $this->options = $options;
        $this->dispatchId = $dispatchId;
        $this->lockStore = $lockStore;
        $this->lockSeconds = $lockSeconds;
        $this->lockRetrySeconds = $lockRetrySeconds;
        $this->tries = QueueRetryPolicy::normalizeTries($tries);
        $this->backoffSeconds = QueueRetryPolicy::normalizeBackoffSeconds($backoffSeconds);
    }

    public function handle(FlowEngine $flow, CacheFactory $cache, ConfigRepository $config): ?FlowRun
    {
        $repository = $cache->store($this->lockStore);
        $store = $repository->getStore();

        if ($store instanceof ArrayStore && ! $this->allowsProcessLocalLocks($config)) {
            throw new RuntimeException('Laravel Flow queued execution requires a shared cache lock store; the array store is process-local.');
        }

        if (! ($store instanceof LockProvider)) {
            throw new RuntimeException('Laravel Flow queued execution requires a cache store that supports atomic locks.');
        }

        if ($this->completionRecorded($repository)) {
            return null;
        }

        $lock = $store->lock($this->lockKey(), $this->lockSeconds());

        if (! $lock->get()) {
            // InteractsWithQueue marks the underlying Laravel job as released;
            // CallQueuedHandler will not delete a released job.
            $this->release($this->lockRetrySeconds());

            return null;
        }

        $releaseLock = true;

        try {
            if ($this->completionRecorded($repository)) {
                return null;
            }

            $run = $flow->execute($this->name, $this->input, $this->options);

            $releaseLock = false;

            try {
                $completionRecorded = $repository->put($this->completionKey(), true, $this->lockSeconds());
            } catch (Throwable $e) {
                $completionRecorded = false;
                $exception = new RuntimeException(
                    'Laravel Flow queued execution could not record the dispatch completion marker.',
                    previous: $e,
                );
            }

            if ($completionRecorded !== true) {
                $exception ??= new RuntimeException('Laravel Flow queued execution could not record the dispatch completion marker.');

                if ($this->job !== null) {
                    $this->fail($exception);
                }

                throw $exception;
            }

            $releaseLock = true;

            return $run;
        } finally {
            if ($releaseLock) {
                $lock->release();
            }
        }
    }

    public function tries(): ?int
    {
        return $this->tries;
    }

    /**
     * @return int|list<int>|null
     */
    public function backoff(): int|array|null
    {
        return QueueRetryPolicy::normalizeBackoffSeconds($this->backoffSeconds);
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
        if ($this->dispatchId === null || $this->dispatchId === '') {
            $this->dispatchId = 'legacy-'.bin2hex(random_bytes(16));
        }

        return $this->dispatchId;
    }

    private function lockSeconds(): int
    {
        return max(1, $this->lockSeconds);
    }

    private function lockRetrySeconds(): int
    {
        return max(1, min($this->lockSeconds(), $this->lockRetrySeconds));
    }

    /**
     * @phpstan-impure The cache backend can change between duplicate workers.
     */
    private function completionRecorded(CacheRepository $repository): bool
    {
        return $repository->get($this->completionKey()) === true;
    }

    private function allowsProcessLocalLocks(ConfigRepository $config): bool
    {
        $connection = $this->job?->getConnectionName();

        if (! is_string($connection) || $connection === '') {
            $connection = $config->get('queue.default');
        }

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return $config->get('queue.connections.'.$connection.'.driver') === 'sync';
    }
}
