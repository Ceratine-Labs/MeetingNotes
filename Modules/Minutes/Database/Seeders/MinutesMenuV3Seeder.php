<?php

namespace Modules\Minutes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * v3: adds the cross-meeting Action items register to the sidebar.
 *
 * A new class rather than an edit to V2, per the seed master's rule that a
 * changed seeder is a new seeder.
 */
class MinutesMenuV3Seeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 30;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'minutes.actions.index',
                'label' => 'Action items',
                'icon' => 'list-check',
                'section' => 'Minutes',
                'sort' => 105,
            ],
        ]);
    }
}
