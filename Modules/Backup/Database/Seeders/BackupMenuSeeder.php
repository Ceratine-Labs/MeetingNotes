<?php

namespace Modules\Backup\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

class BackupMenuSeeder extends Seeder
{
    public $order = 10;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'backup.admin.index',
                'label' => 'Backups',
                'icon' => 'hdd-stack',
                'section' => 'Admin',
                'required_role' => 'admin',
                'sort' => 240,
            ],
        ]);
    }
}
