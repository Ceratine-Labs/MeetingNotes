<?php

return [
    'name' => 'Llm',

    // Providers the admin can pick from. 'openai_compatible' covers
    // Ollama / LM Studio / vLLM / OpenRouter via a configurable base URL.
    'providers' => [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
        'openai_compatible' => 'OpenAI-compatible endpoint',
    ],

    // Task types the pipeline distinguishes — each gets its own
    // admin-configurable model so cheap models can do map work.
    'task_types' => [
        'generate_full' => 'Full minutes generation',
        'chunk_map' => 'Chunk extraction (map)',
        'chunk_reduce' => 'Chunk merge (reduce)',
        'regenerate_section' => 'Single-section regeneration',
    ],

    // Defaults seeded on install; admin overrides live in settings.
    'default_models' => [
        'anthropic' => [
            'generate_full' => 'claude-sonnet-4-6',
            'chunk_map' => 'claude-haiku-4-5-20251001',
            'chunk_reduce' => 'claude-sonnet-4-6',
            'regenerate_section' => 'claude-sonnet-4-6',
        ],
        'openai' => [
            'generate_full' => 'gpt-4o',
            'chunk_map' => 'gpt-4o-mini',
            'chunk_reduce' => 'gpt-4o',
            'regenerate_section' => 'gpt-4o',
        ],
        'openai_compatible' => [
            'generate_full' => '',
            'chunk_map' => '',
            'chunk_reduce' => '',
            'regenerate_section' => '',
        ],

        // The in-process FakeDriver (dev/demo/E2E). Not listed in
        // 'providers' above on purpose: it must never appear in the admin
        // dropdown, and its driver refuses to construct in production.
        'fake' => [
            'generate_full' => 'fake',
            'chunk_map' => 'fake',
            'chunk_reduce' => 'fake',
            'regenerate_section' => 'fake',
        ],
    ],

    // USD per 1M tokens [input, output] for cost estimates on the
    // generation-run log. Unknown models simply log null.
    'pricing' => [
        'claude-fable-5' => [5.00, 25.00],
        'claude-opus-4-8' => [5.00, 25.00],
        'claude-sonnet-4-6' => [3.00, 15.00],
        'claude-haiku-4-5-20251001' => [1.00, 5.00],
        'gpt-4o' => [2.50, 10.00],
        'gpt-4o-mini' => [0.15, 0.60],
    ],

    'defaults' => [
        'temperature' => 0.2,
        'max_tokens' => 8192,
        'timeout' => 300,
    ],
];
