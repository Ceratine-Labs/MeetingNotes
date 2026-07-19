<?php

namespace Modules\Core\Services;

/**
 * Discovers modules by scanning Modules/[*]/module.json and exposes
 * their metadata. CoreServiceProvider uses this at register() time to
 * register every discovered provider automatically — bootstrap/
 * providers.php only ever lists Core itself.
 *
 * Discovery is priority-ordered (module.json "priority", lower boots
 * first; Core is 0) so foundational bindings exist before dependents.
 */
class ModuleRegistry
{
    /** @var array<string, array>|null */
    protected static ?array $modules = null;

    /**
     * All discovered modules keyed by name, sorted by priority.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        if (static::$modules !== null) {
            return static::$modules;
        }

        $modules = [];

        foreach (glob(base_path('Modules/*/module.json')) as $manifest) {
            $meta = json_decode(file_get_contents($manifest), true);

            if (! is_array($meta) || empty($meta['name'])) {
                continue;
            }

            $meta['path'] = dirname($manifest);
            $modules[$meta['name']] = $meta;
        }

        uasort($modules, fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));

        return static::$modules = $modules;
    }

    /**
     * Provider class names of every module except Core (Core registers
     * itself via bootstrap/providers.php).
     *
     * @return string[]
     */
    public static function providers(): array
    {
        $providers = [];

        foreach (static::all() as $name => $meta) {
            if ($name === 'Core') {
                continue;
            }

            foreach ($meta['providers'] ?? [] as $provider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Absolute paths of every module's Database/Seeders directory that
     * exists, in module priority order. Consumed by the seed master.
     *
     * @return array<string, string> module name => seeders path
     */
    public static function seederPaths(): array
    {
        $paths = [];

        foreach (static::all() as $name => $meta) {
            $dir = $meta['path'] . '/Database/Seeders';

            if (is_dir($dir)) {
                $paths[$name] = $dir;
            }
        }

        return $paths;
    }
}
