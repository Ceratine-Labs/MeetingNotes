<?php

namespace Modules\Minutes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * v2 of the Minutes sidebar entries — Bootstrap Icons names replaced with Tabler ones.
 *
 * A new class rather than an edit, per the seed master's rule that a changed seeder is
 * a new seeder.
 */
class MinutesMenuV2Seeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 20;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'minutes.index',
                'label' => 'Library',
                'icon' => 'files',
                'section' => 'Minutes',
                'sort' => 100,
            ],
            [
                'route_name' => 'minutes.create',
                'label' => 'New minutes',
                'icon' => 'circle-plus',
                'section' => 'Minutes',
                'sort' => 110,
            ],
        ]);
    }
}
