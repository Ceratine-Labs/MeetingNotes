<?php

namespace Modules\Llm\Contracts;

use Modules\Llm\Support\LlmResponse;

interface LlmDriver
{
    /**
     * Plain text completion.
     */
    public function complete(string $model, string $system, string $user, array $options = []): LlmResponse;

    /**
     * Structured completion — the provider is forced to return JSON
     * conforming to $schema (JSON Schema, draft 2020-12 subset).
     * LlmResponse::$content is the decoded array.
     */
    public function structured(string $model, string $system, string $user, array $schema, array $options = []): LlmResponse;
}
