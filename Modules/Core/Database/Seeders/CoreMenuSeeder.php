<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

class CoreMenuSeeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower first). */
    public $order = 10;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'core.dashboard',
                'label' => 'Dashboard',
                'icon' => 'speedometer2',
                'section' => null,
                'sort' => 10,
            ],
        ]);
    }
}
