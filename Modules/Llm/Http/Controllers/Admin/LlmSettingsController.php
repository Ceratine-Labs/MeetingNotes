<?php

namespace Modules\Llm\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\SettingsService;
use Modules\Llm\Services\LlmManager;

class LlmSettingsController extends Controller
{
    public function __construct(protected SettingsService $settings)
    {
    }

    public function edit()
    {
        return view('llm::admin.settings', [
            'providers' => config('llm.providers'),
            'taskTypes' => config('llm.task_types'),
            'current' => [
                'provider' => $this->settings->get('llm.provider', 'anthropic'),
                'temperature' => $this->settings->get('llm.temperature'),
                'max_tokens' => $this->settings->get('llm.max_tokens'),
                'timeout' => $this->settings->get('llm.timeout'),
                'compatible_base_url' => $this->settings->get('llm.compatible.base_url'),
                'models' => collect(config('llm.task_types'))->mapWithKeys(fn ($label, $type) => [
                    $type => $this->settings->get("llm.model.{$type}"),
                ]),
            ],
            // Never send key values to the browser — only configured hints.
            'keyHints' => [
                'anthropic' => $this->settings->filled('llm.anthropic.api_key') ? $this->settings->hint('llm.anthropic.api_key') : null,
                'openai' => $this->settings->filled('llm.openai.api_key') ? $this->settings->hint('llm.openai.api_key') : null,
                'compatible' => $this->settings->filled('llm.compatible.api_key') ? $this->settings->hint('llm.compatible.api_key') : null,
            ],
            'defaultModels' => config('llm.default_models'),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:' . implode(',', array_keys(config('llm.providers')))],
            'temperature' => ['required', 'numeric', 'between:0,2'],
            'max_tokens' => ['required', 'integer', 'between:256,64000'],
            'timeout' => ['required', 'integer', 'between:10,900'],
            'compatible_base_url' => ['nullable', 'url'],
            'models' => ['array'],
            'models.*' => ['nullable', 'string', 'max:120'],
            'anthropic_api_key' => ['nullable', 'string', 'max:300'],
            'openai_api_key' => ['nullable', 'string', 'max:300'],
            'compatible_api_key' => ['nullable', 'string', 'max:300'],
        ]);

        $this->settings->set('llm.provider', $validated['provider'], 'llm');
        $this->settings->set('llm.temperature', (string) $validated['temperature'], 'llm');
        $this->settings->set('llm.max_tokens', (string) $validated['max_tokens'], 'llm');
        $this->settings->set('llm.timeout', (string) $validated['timeout'], 'llm');

        if (array_key_exists('compatible_base_url', $validated)) {
            $this->settings->set('llm.compatible.base_url', $validated['compatible_base_url'], 'llm');
        }

        foreach ($validated['models'] ?? [] as $type => $model) {
            if (array_key_exists($type, config('llm.task_types'))) {
                $this->settings->set("llm.model.{$type}", $model ?: null, 'llm');
            }
        }

        // Blank key fields mean "keep the stored key" — an empty input
        // never wipes a configured secret.
        foreach (['anthropic', 'openai', 'compatible'] as $vendor) {
            $key = $validated["{$vendor}_api_key"] ?? null;

            if ($key) {
                $this->settings->set("llm.{$vendor}.api_key", $key, 'llm', encrypt: true);
            }
        }

        return redirect()->route('llm.admin.settings')->with('status', 'LLM settings saved.');
    }

    public function testConnection(LlmManager $manager)
    {
        return response()->json($manager->testConnection());
    }
}
