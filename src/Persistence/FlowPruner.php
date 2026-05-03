<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
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

        if ($dryRun) {
            return $this->countMatchingRows($connection, $finishedBefore);
        }

        $runs = 0;
        $steps = 0;
        $audit = 0;

        while (true) {
            $runIds = $this->matchingRunIds($connection, $finishedBefore)
                ->orderBy('finished_at')
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($runIds === []) {
                break;
            }

            $connection->transaction(function () use ($connection, $runIds, &$runs, &$steps, &$audit): void {
                $audit += (int) $connection->table('flow_audit')
                    ->whereIn('run_id', $runIds)
                    ->delete();

                $steps += (int) $connection->table('flow_steps')
                    ->whereIn('run_id', $runIds)
                    ->delete();

                $runs += (int) $connection->table('flow_runs')
                    ->whereIn('id', $runIds)
                    ->delete();
            });
        }

        return new FlowPruneResult($runs, $steps, $audit);
    }

    private function countMatchingRows(ConnectionInterface $connection, DateTimeInterface $finishedBefore): FlowPruneResult
    {
        return new FlowPruneResult(
            runs: (int) $this->matchingRunIds($connection, $finishedBefore)->count(),
            steps: (int) $connection->table('flow_steps')
                ->whereIn('run_id', $this->matchingRunIds($connection, $finishedBefore))
                ->count(),
            audit: (int) $connection->table('flow_audit')
                ->whereIn('run_id', $this->matchingRunIds($connection, $finishedBefore))
                ->count(),
        );
    }

    private function matchingRunIds(ConnectionInterface $connection, DateTimeInterface $finishedBefore): Builder
    {
        return $connection->table('flow_runs')
            ->select('id')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $finishedBefore)
            ->whereIn('status', self::TERMINAL_STATUSES);
    }
}
