<?php

namespace Modules\Llm\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

class LlmMenuSeeder extends Seeder
{
    public $order = 30;

    public function run(): void
    {
        MenuService::seed([
            [
                'route_name' => 'llm.admin.settings',
                'label' => 'LLM Settings',
                'icon' => 'cpu',
                'section' => 'Admin',
                'required_role' => 'admin',
                'sort' => 210,
            ],
            [
                'route_name' => 'llm.admin.prompts',
                'label' => 'Prompt Templates',
                'icon' => 'chat-square-text',
                'section' => 'Admin',
                'required_role' => 'admin',
                'sort' => 220,
            ],
            [
                'route_name' => 'llm.admin.runs',
                'label' => 'Generation Log',
                'icon' => 'activity',
                'section' => 'Admin',
                'required_role' => 'admin',
                'sort' => 230,
            ],
        ]);
    }
}
