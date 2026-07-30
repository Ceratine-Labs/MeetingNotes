<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Plan;
use Modules\Core\Services\MenuService;
use Modules\Tenancy\Models\Membership;

/**
 * v2 of the Billing sidebar entry — now owner-only.
 *
 * The v1 seeder left `required_role` unset because MenuService only understood the
 * legacy user-level role and knew nothing about workspace membership. It now
 * interprets the column as a workspace role, so the entry can be restricted to
 * owners — matching the route's own `organisation.role:owner` gate.
 *
 * That closes the gap noted in BillingMenuSeeder: a workspace admin no longer sees a
 * billing link that would 403.
 */
class BillingMenuV2Seeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 30;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'billing.index',
                'label' => 'Billing',
                'icon' => 'credit-card',
                'section' => 'Workspace',
                'required_role' => Membership::ROLE_OWNER,
                'sort' => 80,
            ],
        ]);

        // Sanity check, not decoration: registration provisions every new workspace
        // onto this plan, so its absence breaks sign-up entirely. Better to say so
        // during seeding than to discover it from a customer's failed registration.
        if (! Plan::query()->where('code', Plan::CODE_FREE)->exists()) {
            $this->command?->error('No free plan exists — registration will fail. Run PlanSeeder.');
        }
    }
}
