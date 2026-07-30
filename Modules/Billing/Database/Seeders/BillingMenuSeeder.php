<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Sidebar entry for the billing screens (house hard rule #6 — navigation is
 * database-driven, never hardcoded in Blade).
 *
 * No `required_role` is set here, because MenuService only understands the legacy
 * user-level role and knows nothing about per-workspace membership roles. The
 * route itself is owner-gated (`organisation.role:owner`), so a non-owner who
 * clicks this gets a clean 403 rather than a broken page. Teaching MenuService
 * about workspace roles so the entry can be hidden entirely is captured as
 * follow-up work rather than bodged in here.
 */
class BillingMenuSeeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 20;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'billing.index',
                'label' => 'Billing',
                'icon' => 'credit-card',
                'section' => 'Workspace',
                'sort' => 80,
            ],
        ]);
    }
}
