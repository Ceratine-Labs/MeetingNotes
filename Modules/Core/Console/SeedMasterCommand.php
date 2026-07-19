<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\SeedMaster;

/**
 * `php artisan seed:master` — the ONLY sanctioned way to run seeders
 * in this codebase. Plain `db:seed` delegates here via DatabaseSeeder,
 * so deploy scripts and `migrate --seed` stay idempotent.
 */
class SeedMasterCommand extends Command
{
    protected $signature = 'seed:master
        {--status : Show executed vs pending seeders without running anything}
        {--force= : Re-run a single seeder by FQCN (records a new batch row)}';

    protected $description = 'Run module seeders exactly once each, tracked in seed_registry';

    public function handle(SeedMaster $master): int
    {
        if ($this->option('status')) {
            $this->table(
                ['Seeder', 'Status', 'Batch', 'Executed at'],
                collect($master->status())->map(fn ($r) => [
                    $r['class'],
                    $r['status'],
                    $r['batch'] ?? '—',
                    $r['executed_at'] ?? '—',
                ])
            );

            return self::SUCCESS;
        }

        if ($force = $this->option('force')) {
            $master->force($force);
            $this->info("Force-ran {$force} (new batch row recorded).");

            return self::SUCCESS;
        }

        [$ran, $skipped, $warnings] = $master->run(fn ($class) => $this->line("  <info>ran</info> {$class}"));

        $this->info(sprintf('Seed master: %d ran, %d already executed.', count($ran), count($skipped)));

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return $warnings === [] ? self::SUCCESS : self::FAILURE;
    }
}
