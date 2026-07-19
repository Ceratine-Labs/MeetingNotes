<?php

namespace Modules\Llm\Drivers;

use Modules\Llm\Exceptions\LlmException;
use Modules\Llm\Support\LlmResponse;

/**
 * Any OpenAI-compatible endpoint (Ollama, LM Studio, vLLM, OpenRouter…).
 * Strict json_schema support varies wildly across these servers, so
 * structured output falls back to json_object mode with the schema
 * embedded in the prompt, plus server-side validation upstream.
 */
class OpenAiCompatibleDriver extends OpenAiDriver
{
    public function structured(string $model, string $system, string $user, array $schema, array $options = []): LlmResponse
    {
        $payload = $this->payload(
            $model,
            $system . "\n\nRespond ONLY with a JSON object valid against this JSON Schema:\n"
                . json_encode($schema, JSON_PRETTY_PRINT),
            $user,
            $options
        );
        $payload['response_format'] = ['type' => 'json_object'];

        $data = $this->request($payload);
        $raw = $data['choices'][0]['message']['content'] ?? '';

        // Some local servers wrap JSON in markdown fences regardless.
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new LlmException('Compatible endpoint returned non-JSON content for structured request.');
        }

        return $this->response($decoded, $data, $model);
    }
}
