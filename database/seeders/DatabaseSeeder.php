<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\SeedMaster;

/**
 * Delegates to the seed master so `db:seed` / `migrate --seed` stay
 * idempotent — nothing seeds twice, ever. See `seed:master --status`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(SeedMaster $master): void
    {
        [$ran, $skipped, $warnings] = $master->run(
            fn ($class) => $this->command?->line("  ran {$class}")
        );

        $this->command?->info(sprintf(
            'Seed master: %d ran, %d already executed.',
            count($ran),
            count($skipped)
        ));

        foreach ($warnings as $warning) {
            $this->command?->warn($warning);
        }
    }
}
