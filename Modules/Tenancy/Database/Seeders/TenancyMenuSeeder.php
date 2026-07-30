<?php

namespace Modules\Tenancy\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;
use Modules\Tenancy\Models\Membership;

/**
 * Sidebar entries for workspace administration.
 *
 * Both carry `required_role`, which MenuService now interprets as a WORKSPACE role
 * rather than the old user-level one. An ordinary member therefore does not see these
 * links at all, instead of seeing them and getting a 403 — the routes themselves are
 * gated by `organisation.role:admin`, and the menu now agrees with the routes.
 */
class TenancyMenuSeeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 10;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'tenancy.members.index',
                'label' => 'Members',
                'icon' => 'users',
                'section' => 'Workspace',
                'required_role' => Membership::ROLE_ADMIN,
                'sort' => 60,
            ],
            [
                'route_name' => 'tenancy.organisations.edit',
                'label' => 'Workspace settings',
                'icon' => 'settings',
                'section' => 'Workspace',
                'required_role' => Membership::ROLE_ADMIN,
                'sort' => 70,
            ],
        ]);
    }
}
