<?php

namespace Modules\Minutes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

class MinutesMenuSeeder extends Seeder
{
    public $order = 10;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'minutes.index',
                'label' => 'Library',
                'icon' => 'collection',
                'section' => 'Minutes',
                'sort' => 100,
            ],
            [
                'route_name' => 'minutes.create',
                'label' => 'New minutes',
                'icon' => 'plus-circle',
                'section' => 'Minutes',
                'sort' => 110,
            ],
        ]);
    }
}
