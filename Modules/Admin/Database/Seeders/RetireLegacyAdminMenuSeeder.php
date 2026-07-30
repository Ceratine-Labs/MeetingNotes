<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Removes the LLM and Backup screens from the CUSTOMER sidebar.
 *
 * Those four entries were seeded when the application was single-tenant and its only
 * admin was a `users.role = 'admin'` account. They now live in the back office behind
 * the `admin` guard, so leaving them in the customer sidebar would show every signed-in
 * customer links that bounce them to the staff login page.
 *
 * The back office reaches the same screens through its own static navigation
 * (admin::layouts.app), so nothing is lost — only the customer-side rows are retired.
 *
 * Deactivated rather than deleted, so the rows and their labels survive if the decision
 * is ever reversed.
 */
class RetireLegacyAdminMenuSeeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 20;

    public function run(): void
    {
        MenuService::retire([
            'llm.admin.settings',
            'llm.admin.prompts',
            'llm.admin.runs',
            'backup.admin.index',
        ]);

        $this->command?->line('Retired 4 legacy admin entries from the customer sidebar.');
    }
}
