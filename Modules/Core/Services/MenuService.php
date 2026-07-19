<?php

namespace Modules\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Menu;

/**
 * DB-driven sidebar (house hard rule #6 — no static blade sidebars).
 * Modules seed entries from their MenuSeeder via seed(); the layout
 * asks visibleFor() at render time.
 */
class MenuService
{
    protected const CACHE_TTL = 300;

    /**
     * Upsert menu entries (keyed by route_name). Called from module
     * MenuSeeders through the seed master, so this runs once per
     * seeder lifetime.
     *
     * @param  array<int, array>  $items
     */
    public static function seed(array $items): void
    {
        foreach ($items as $item) {
            Menu::query()->updateOrCreate(
                ['route_name' => $item['route_name']],
                $item + ['is_active' => true, 'sort' => 100]
            );
        }

        Cache::forget('core.menu.tree');
    }

    /**
     * Sidebar entries the given user may see, grouped by section and
     * filtered to routes that actually exist.
     *
     * Only plain arrays go into the cache — never Eloquent models or
     * Collection objects. Serialized objects in the database cache
     * store unserialize as __PHP_Incomplete_Class in other processes.
     */
    public function visibleFor(?object $user): Collection
    {
        $rows = Cache::remember('core.menu.tree', self::CACHE_TTL, function () {
            return Menu::query()
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('label')
                ->get()
                ->map(fn (Menu $m) => $m->only(['section', 'label', 'route_name', 'icon', 'required_role']))
                ->all();
        });

        return collect($rows)
            ->map(fn (array $r) => (object) $r)
            ->filter(fn (object $m) => Route::has($m->route_name))
            ->filter(function (object $m) use ($user) {
                return $m->required_role === null
                    || ($user !== null && $user->role === $m->required_role);
            })
            ->groupBy(fn (object $m) => $m->section ?? '');
    }
}
