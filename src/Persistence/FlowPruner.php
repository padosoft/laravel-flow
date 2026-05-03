<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Padosoft\LaravelFlow\FlowRun;

final class FlowPruner
{
    /**
     * @var list<string>
     */
    private const TERMINAL_STATUSES = [
        FlowRun::STATUS_ABORTED,
        FlowRun::STATUS_COMPENSATED,
        FlowRun::STATUS_FAILED,
        FlowRun::STATUS_SUCCEEDED,
    ];

    public function __construct(
        private readonly ?string $connection,
    ) {}

    public function prune(DateTimeInterface $finishedBefore, int $chunkSize = 500, bool $dryRun = false): FlowPruneResult
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Flow prune chunk size must be at least 1.');
        }

        $connection = DB::connection($this->connection);

        $runs = 0;
        $steps = 0;
        $audit = 0;
        $afterFinishedAt = null;
        $afterId = null;

        while (true) {
            $batch = $this->matchingRunIdBatch(
                $connection,
                $finishedBefore,
                $chunkSize,
                $dryRun ? $afterFinishedAt : null,
                $dryRun ? $afterId : null,
            );

            if ($batch->isEmpty()) {
                break;
            }

            $runIds = $batch
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($runIds === []) {
                break;
            }

            if ($dryRun) {
                $runs += count($runIds);
                $steps += (int) $connection->table('flow_steps')
                    ->whereIn('run_id', $runIds)
                    ->count();
                $audit += (int) $connection->table('flow_audit')
                    ->whereIn('run_id', $runIds)
                    ->count();

                /** @var object{id:mixed, finished_at:mixed} $last */
                $last = $batch->last();
                $afterFinishedAt = (string) $last->finished_at;
                $afterId = (string) $last->id;

                continue;
            }

            $connection->transaction(function (ConnectionInterface $connection) use ($runIds, &$runs, &$steps, &$audit): void {
                $steps += (int) $connection->table('flow_steps')
                    ->whereIn('run_id', $runIds)
                    ->count();

                $audit += (int) $connection->table('flow_audit')
                    ->whereIn('run_id', $runIds)
                    ->delete();

                $runs += (int) $connection->table('flow_runs')
                    ->whereIn('id', $runIds)
                    ->delete();
            });
        }

        return new FlowPruneResult($runs, $steps, $audit);
    }

    /**
     * @return Collection<int, object{id:mixed, finished_at:mixed}>
     */
    private function matchingRunIdBatch(
        ConnectionInterface $connection,
        DateTimeInterface $finishedBefore,
        int $chunkSize,
        ?string $afterFinishedAt,
        ?string $afterId,
    ): Collection {
        return $this->matchingRunIds($connection, $finishedBefore, $afterFinishedAt, $afterId)
            ->orderBy('finished_at')
            ->orderBy('id')
            ->limit($chunkSize)
            ->get();
    }

    private function matchingRunIds(
        ConnectionInterface $connection,
        DateTimeInterface $finishedBefore,
        ?string $afterFinishedAt,
        ?string $afterId,
    ): Builder {
        $query = $connection->table('flow_runs')
            ->select(['id', 'finished_at'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $finishedBefore)
            ->whereIn('status', self::TERMINAL_STATUSES);

        if ($afterFinishedAt !== null && $afterId !== null) {
            $query->where(function (Builder $query) use ($afterFinishedAt, $afterId): void {
                $query->where('finished_at', '>', $afterFinishedAt)
                    ->orWhere(function (Builder $query) use ($afterFinishedAt, $afterId): void {
                        $query->where('finished_at', '=', $afterFinishedAt)
                            ->where('id', '>', $afterId);
                    });
            });
        }

        return $query;
    }
}
