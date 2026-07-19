<?php

namespace Modules\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use Modules\Llm\Contracts\LlmDriver;
use Modules\Llm\Exceptions\LlmException;
use Modules\Llm\Support\LlmResponse;

/**
 * OpenAI Chat Completions. Structured output via response_format
 * json_schema (strict mode).
 */
class OpenAiDriver implements LlmDriver
{
    public function __construct(
        protected string $apiKey,
        protected int $timeout = 300,
        protected string $baseUrl = 'https://api.openai.com/v1',
    ) {
    }

    public function complete(string $model, string $system, string $user, array $options = []): LlmResponse
    {
        $data = $this->request($this->payload($model, $system, $user, $options));

        return $this->response((string) ($data['choices'][0]['message']['content'] ?? ''), $data, $model);
    }

    public function structured(string $model, string $system, string $user, array $schema, array $options = []): LlmResponse
    {
        $payload = $this->payload($model, $system, $user, $options);
        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => ['name' => 'result', 'schema' => $schema, 'strict' => false],
        ];

        $data = $this->request($payload);
        $decoded = json_decode($data['choices'][0]['message']['content'] ?? '', true);

        if (! is_array($decoded)) {
            throw new LlmException('OpenAI returned non-JSON content for structured request.');
        }

        return $this->response($decoded, $data, $model);
    }

    protected function payload(string $model, string $system, string $user, array $options): array
    {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => $options['max_tokens'] ?? 8192,
            'temperature' => $options['temperature'] ?? 0.2,
        ];
    }

    protected function request(array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $payload);

        if ($response->failed()) {
            throw new LlmException(
                'OpenAI API error ' . $response->status() . ': '
                . ($response->json('error.message') ?? substr($response->body(), 0, 300))
            );
        }

        return $response->json();
    }

    protected function response(string|array $content, array $data, string $model): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            tokensIn: (int) ($data['usage']['prompt_tokens'] ?? 0),
            tokensOut: (int) ($data['usage']['completion_tokens'] ?? 0),
            model: $data['model'] ?? $model,
        );
    }
}
