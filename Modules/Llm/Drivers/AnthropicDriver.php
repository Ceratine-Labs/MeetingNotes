<?php

namespace Modules\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use Modules\Llm\Contracts\LlmDriver;
use Modules\Llm\Exceptions\LlmException;
use Modules\Llm\Support\LlmResponse;

/**
 * Anthropic Messages API. Structured output is enforced via tool use —
 * a single tool whose input_schema is the target schema, with
 * tool_choice forcing the call, which guarantees schema-shaped JSON.
 */
class AnthropicDriver implements LlmDriver
{
    protected const VERSION = '2023-06-01';

    public function __construct(
        protected string $apiKey,
        protected int $timeout = 300,
        protected string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public function complete(string $model, string $system, string $user, array $options = []): LlmResponse
    {
        $data = $this->request([
            'model' => $model,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'max_tokens' => $options['max_tokens'] ?? 8192,
            'temperature' => $options['temperature'] ?? 0.2,
        ]);

        $text = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return $this->response($text, $data, $model);
    }

    public function structured(string $model, string $system, string $user, array $schema, array $options = []): LlmResponse
    {
        $data = $this->request([
            'model' => $model,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'max_tokens' => $options['max_tokens'] ?? 8192,
            'temperature' => $options['temperature'] ?? 0.2,
            'tools' => [[
                'name' => 'emit_result',
                'description' => 'Emit the structured result.',
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'emit_result'],
        ]);

        $input = collect($data['content'] ?? [])->firstWhere('type', 'tool_use')['input'] ?? null;

        if (! is_array($input)) {
            throw new LlmException('Anthropic returned no tool_use block for structured request.');
        }

        return $this->response($input, $data, $model);
    }

    protected function request(array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::VERSION,
            ])
            ->post($this->baseUrl . '/v1/messages', $payload);

        if ($response->failed()) {
            throw new LlmException(
                'Anthropic API error ' . $response->status() . ': '
                . ($response->json('error.message') ?? substr($response->body(), 0, 300))
            );
        }

        return $response->json();
    }

    protected function response(string|array $content, array $data, string $model): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            tokensIn: (int) ($data['usage']['input_tokens'] ?? 0),
            tokensOut: (int) ($data['usage']['output_tokens'] ?? 0),
            model: $data['model'] ?? $model,
        );
    }
}
