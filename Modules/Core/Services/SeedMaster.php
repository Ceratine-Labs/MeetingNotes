<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\SeedRegistry;

/**
 * Migrations-style execution ledger for seeders (house requirement:
 * a seed must never silently run twice).
 *
 * Discovery: every `*Seeder.php` class in each module's
 * Database/Seeders directory, module-priority order, then per-module
 * order by the seeder's public `$order` property (default 100), then
 * class name.
 *
 * Rules:
 *   - Not in seed_registry            -> run, record (class, batch, checksum).
 *   - In registry, checksum matches   -> skip silently.
 *   - In registry, checksum differs   -> WARN and skip. A changed seeder
 *     is a new seeder — make a v2 class. `--force=FQCN` is the only
 *     override and appends a new batch row (an audit trail, not an edit).
 */
class SeedMaster
{
    /**
     * Discover all seeder classes with their source file paths.
     *
     * @return array<class-string, string> FQCN => absolute file path
     */
    public function discover(): array
    {
        $seeders = [];

        foreach (ModuleRegistry::seederPaths() as $module => $dir) {
            $files = glob($dir . '/*Seeder.php');
            $entries = [];

            foreach ($files as $file) {
                $class = "Modules\\{$module}\\Database\\Seeders\\" . basename($file, '.php');

                if (! class_exists($class)) {
                    require_once $file;
                }

                if (! class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $order = (new \ReflectionClass($class))->hasProperty('order')
                    ? ((new \ReflectionClass($class))->getDefaultProperties()['order'] ?? 100)
                    : 100;

                $entries[] = ['class' => $class, 'file' => $file, 'order' => $order];
            }

            usort($entries, fn ($a, $b) => [$a['order'], $a['class']] <=> [$b['order'], $b['class']]);

            foreach ($entries as $entry) {
                $seeders[$entry['class']] = $entry['file'];
            }
        }

        return $seeders;
    }

    /**
     * Status of every discovered seeder.
     *
     * @return array<int, array{class: string, status: string, batch: ?int, executed_at: ?string}>
     */
    public function status(): array
    {
        $executed = $this->registryAvailable()
            ? SeedRegistry::query()->get()->keyBy('seeder_class')
            : collect();

        $rows = [];

        foreach ($this->discover() as $class => $file) {
            $record = $executed->get($class);

            $status = match (true) {
                $record === null => 'pending',
                $record->checksum !== $this->checksum($file) => 'checksum-mismatch',
                default => 'executed',
            };

            $rows[] = [
                'class' => $class,
                'status' => $status,
                'batch' => $record?->batch,
                'executed_at' => $record?->executed_at?->toDateTimeString(),
            ];
        }

        return $rows;
    }

    /**
     * Run every pending seeder. Returns [ran[], skipped[], warnings[]].
     *
     * @param  callable(string):void|null  $onRun  progress callback per executed class
     */
    public function run(?callable $onRun = null): array
    {
        $ran = $skipped = $warnings = [];
        $batch = (int) SeedRegistry::query()->max('batch') + 1;

        foreach ($this->discover() as $class => $file) {
            $record = SeedRegistry::query()->where('seeder_class', $class)->latest('executed_at')->first();

            if ($record) {
                if ($record->checksum !== $this->checksum($file)) {
                    $warnings[] = "{$class}: file changed since it ran (batch {$record->batch}). "
                        . 'NOT re-running — create a v2 seeder, or seed:master --force=' . $class;
                } else {
                    $skipped[] = $class;
                }

                continue;
            }

            $this->execute($class, $file, $batch);
            $ran[] = $class;

            if ($onRun) {
                $onRun($class);
            }
        }

        return [$ran, $skipped, $warnings];
    }

    /**
     * Explicit single re-run. Always executes and appends a new registry
     * row in a fresh batch — never rewrites history.
     */
    public function force(string $class): void
    {
        $seeders = $this->discover();

        if (! isset($seeders[$class])) {
            throw new \InvalidArgumentException("Unknown seeder: {$class}");
        }

        $batch = (int) SeedRegistry::query()->max('batch') + 1;
        $this->execute($class, $seeders[$class], $batch);
    }

    protected function execute(string $class, string $file, int $batch): void
    {
        $seeder = app($class);

        // Laravel seeders expect run() invoked through __invoke/call to
        // resolve dependencies; calling run() via the container keeps
        // method injection working.
        app()->call([$seeder, 'run']);

        SeedRegistry::query()->create([
            'seeder_class' => $class,
            'batch' => $batch,
            'checksum' => $this->checksum($file),
            'executed_at' => now(),
        ]);
    }

    protected function checksum(string $file): string
    {
        return sha1_file($file);
    }

    protected function registryAvailable(): bool
    {
        return Schema::hasTable('seed_registry');
    }
}
