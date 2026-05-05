<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Padosoft\LaravelFlow\Persistence\FlowPruner;
use Throwable;

/**
 * @internal
 */
final class PruneFlowRunsCommand extends Command
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'flow:prune
        {--days= : Prune terminal runs finished more than this many days ago. Defaults to laravel-flow.persistence.retention.days}
        {--database= : Database connection to prune. Defaults to laravel-flow.default_storage}
        {--chunk=500 : Number of runs to delete per transaction}
        {--dry-run : Count matching rows without deleting them}
        {--force : Run without confirmation in production}';

    /**
     * @var string
     */
    protected $description = 'Prune old terminal Laravel Flow persistence records.';

    public function handle(): int
    {
        $config = $this->config();
        $days = $this->positiveInteger(
            $this->optionOrConfig('days', $config->get('laravel-flow.persistence.retention.days')),
        );

        if ($days === null) {
            $this->error('Set --days to a positive integer or configure laravel-flow.persistence.retention.days.');

            return self::FAILURE;
        }

        $chunkSize = $this->positiveInteger($this->option('chunk'));

        if ($chunkSize === null) {
            $this->error('Set --chunk to a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $database = $this->databaseConnection($config);

        try {
            if (! $this->persistenceTablesExist($database)) {
                $this->error('Laravel Flow persistence tables were not found on the selected database connection. Publish and run the migrations before pruning.');

                return self::FAILURE;
            }
        } catch (InvalidArgumentException|QueryException $e) {
            $this->reportDatabaseFailure(
                'Laravel Flow could not access the selected persistence database connection. Check --database and your database configuration.',
                $e,
            );

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirmToProceed('This will permanently delete old Laravel Flow persistence rows.')) {
            return self::FAILURE;
        }

        try {
            $cutoff = Date::now()->subDays($days)->toDateTimeImmutable();
            $result = (new FlowPruner($database))->prune($cutoff, $chunkSize, $dryRun);
        } catch (InvalidArgumentException|QueryException $e) {
            $this->reportDatabaseFailure(
                'Laravel Flow could not prune persistence records. Check the selected database connection and migration state.',
                $e,
            );

            return self::FAILURE;
        }

        $summary = sprintf(
            '%s %d flow run(s), %d step row(s), and %d audit row(s) finished before %s.',
            $dryRun ? 'Matched' : 'Pruned',
            $result->runs,
            $result->steps,
            $result->audit,
            $cutoff->format(DATE_ATOM),
        );

        $this->info($dryRun ? $summary.' No rows were deleted.' : $summary);

        return self::SUCCESS;
    }

    private function config(): ConfigRepository
    {
        /** @var ConfigRepository $config */
        $config = $this->getLaravel()->make('config');

        return $config;
    }

    private function optionOrConfig(string $option, mixed $configured): mixed
    {
        $value = $this->option($option);

        return $value === null || $value === '' ? $configured : $value;
    }

    private function databaseConnection(ConfigRepository $config): ?string
    {
        $database = $this->optionOrConfig('database', $config->get('laravel-flow.default_storage'));

        return is_string($database) && $database !== '' ? $database : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function persistenceTablesExist(?string $database): bool
    {
        $schema = DB::connection($database)->getSchemaBuilder();

        return $schema->hasTable('flow_runs')
            && $schema->hasTable('flow_steps')
            && $schema->hasTable('flow_audit');
    }

    private function reportDatabaseFailure(string $message, Throwable $exception): void
    {
        $this->error($message);

        if ($this->getOutput()->isVerbose()) {
            $this->line($exception->getMessage());
        }
    }
}
