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
 *
 * **`required_role` means a WORKSPACE role, not a user role.** Before the SaaS
 * conversion it held 'admin' and was compared against a `users.role` column that no
 * longer exists. It now holds one of the Membership::ROLE_* values
 * (owner|admin|member) and is compared against the role the viewer holds in their
 * current workspace. Roles are a hierarchy, so an entry marked `admin` is also
 * visible to an owner.
 *
 * The role is passed IN rather than resolved here. Core is the foundation module and
 * must not depend on Tenancy — the caller (Core's layout view composer) resolves the
 * membership and hands over a plain string, which also keeps this class testable
 * without a tenant.
 */
class MenuService
{
    protected const CACHE_TTL = 300;

    /**
     * Workspace roles, most privileged first.
     *
     * Duplicated from Membership::ROLES on purpose: Core cannot import from Tenancy
     * without inverting the module dependency. It is three strings, and the
     * comparison in roleAllows() fails closed on an unrecognised value — so drift
     * here hides menu entries rather than exposing them.
     *
     * @var list<string>
     */
    protected const ROLE_HIERARCHY = ['owner', 'admin', 'member'];

    /**
     * Upsert menu entries (keyed by route_name). Called from module
     * MenuSeeders through the seed master, so this runs once per
     * seeder lifetime.
     *
     * @param  array<int, array<string, mixed>>  $items
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
     * Hide menu entries without deleting them.
     *
     * Needed when a screen leaves the customer sidebar — the Llm and Backup admin
     * screens moved to the back office, for instance. Deactivating rather than
     * deleting keeps the row (with its label and sort order) recoverable if the
     * decision is reversed, and a later seeder can re-activate it by upserting.
     *
     * @param  list<string>  $routeNames
     */
    public static function retire(array $routeNames): void
    {
        Menu::query()->whereIn('route_name', $routeNames)->update(['is_active' => false]);

        Cache::forget('core.menu.tree');
    }

    /**
     * Sidebar entries the given viewer may see, grouped by section and
     * filtered to routes that actually exist.
     *
     * Only plain arrays go into the cache — never Eloquent models or
     * Collection objects. Serialized objects in the database cache
     * store unserialize as __PHP_Incomplete_Class in other processes.
     *
     * @param  object|null  $user  The signed-in user, or null for a guest.
     * @param  string|null  $organisationRole  The viewer's role in their current
     *         workspace (owner|admin|member), or null when they have none. Entries
     *         carrying a `required_role` are hidden when this is null.
     * @return Collection<string, Collection<int, object>>
     */
    public function visibleFor(?object $user, ?string $organisationRole = null): Collection
    {
        $rows = Cache::remember('core.menu.tree', self::CACHE_TTL, function (): array {
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
            ->filter(fn (object $m) => $this->roleAllows($m->required_role, $user, $organisationRole))
            ->groupBy(fn (object $m) => $m->section ?? '');
    }

    /**
     * May a viewer holding $organisationRole see an entry requiring $required?
     *
     * Fails closed in every uncertain case — a guest, no workspace role, or a role
     * name neither side recognises. A menu link that should not be there leads to a
     * 403 at best, and advertises a screen the user cannot reach at worst.
     */
    protected function roleAllows(?string $required, ?object $user, ?string $organisationRole): bool
    {
        // No requirement: visible to any signed-in user.
        if ($required === null) {
            return $user !== null;
        }

        if ($user === null || $organisationRole === null) {
            return false;
        }

        $holds = array_search($organisationRole, self::ROLE_HIERARCHY, true);
        $needs = array_search($required, self::ROLE_HIERARCHY, true);

        if ($holds === false || $needs === false) {
            return false;
        }

        // Lower index = more privileged, so holding <= needing means "at least".
        return $holds <= $needs;
    }
}
