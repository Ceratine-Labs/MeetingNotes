<?php

namespace Modules\Llm\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\SettingsService;

/**
 * Default LLM configuration. API keys are deliberately NOT seeded —
 * the admin enters them in Admin → LLM Settings.
 */
class LlmSettingsSeeder extends Seeder
{
    public $order = 20;

    public function run(): void
    {
        $settings = app(SettingsService::class);

        $defaults = [
            'llm.provider' => 'anthropic',
            'llm.temperature' => (string) config('llm.defaults.temperature'),
            'llm.max_tokens' => (string) config('llm.defaults.max_tokens'),
            'llm.timeout' => (string) config('llm.defaults.timeout'),
        ];

        foreach ($defaults as $key => $value) {
            if (! $settings->filled($key)) {
                $settings->set($key, $value, 'llm');
            }
        }
    }
}
