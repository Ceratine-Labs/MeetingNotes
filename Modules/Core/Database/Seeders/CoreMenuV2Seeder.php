<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * v2 of the Core sidebar entries.
 *
 * A new class rather than an edit to CoreMenuSeeder, because the seed master refuses
 * to re-run a seeder whose file has changed (it warns about the checksum mismatch and
 * skips). "A changed seeder is a new seeder" — so changes ship as a vN class.
 *
 * What changed: the icon. v1 used Bootstrap Icons names ('speedometer2'), which stopped
 * resolving when the UI moved to Tabler's icon set.
 */
class CoreMenuV2Seeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 20;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'core.dashboard',
                'label' => 'Dashboard',
                // Tabler icon name, rendered by the sidebar as `ti ti-dashboard`.
                'icon' => 'dashboard',
                'section' => null,
                'sort' => 10,
            ],
        ]);
    }
}
