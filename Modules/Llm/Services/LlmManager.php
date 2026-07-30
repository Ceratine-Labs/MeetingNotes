<?php

namespace Modules\Llm\Services;

use Modules\Core\Services\SettingsService;
use Modules\Llm\Contracts\LlmDriver;
use Modules\Llm\Drivers\AnthropicDriver;
use Modules\Llm\Drivers\FakeDriver;
use Modules\Llm\Drivers\OpenAiCompatibleDriver;
use Modules\Llm\Drivers\OpenAiDriver;
use Modules\Llm\Exceptions\LlmException;
use Modules\Llm\Models\GenerationRun;
use Modules\Llm\Support\LlmResponse;

/**
 * Central entry point for every LLM call the app makes. Resolves the
 * admin-configured provider/model, executes, and writes a
 * generation_runs row (tokens, cost estimate, latency, error) for the
 * admin System page. Nothing outside this module talks to a provider
 * directly.
 */
class LlmManager
{
    public function __construct(protected SettingsService $settings)
    {
    }

    /**
     * Structured call for a pipeline task type. Persists a
     * GenerationRun either way; rethrows on failure.
     */
    public function structured(
        string $taskType,
        string $system,
        string $user,
        array $schema,
        ?string $meetingId = null,
        ?string $promptTemplateId = null,
    ): LlmResponse {
        return $this->execute($taskType, $meetingId, $promptTemplateId, function ($driver, $model, $options) use ($system, $user, $schema) {
            return $driver->structured($model, $system, $user, $schema, $options);
        });
    }

    /**
     * Plain-text call for a pipeline task type, same logging contract.
     */
    public function complete(
        string $taskType,
        string $system,
        string $user,
        ?string $meetingId = null,
        ?string $promptTemplateId = null,
    ): LlmResponse {
        return $this->execute($taskType, $meetingId, $promptTemplateId, function ($driver, $model, $options) use ($system, $user) {
            return $driver->complete($model, $system, $user, $options);
        });
    }

    /**
     * 1-token round trip against the configured provider — powers the
     * admin "Test connection" button. Never throws.
     *
     * @return array{ok: bool, message: string, latency_ms: ?int}
     */
    public function testConnection(): array
    {
        try {
            $start = hrtime(true);
            $response = $this->driver()->complete(
                $this->modelFor('generate_full'),
                'Reply with the single word: ok',
                'ping',
                ['max_tokens' => 5, 'temperature' => 0.0],
            );
            $latency = (int) ((hrtime(true) - $start) / 1e6);

            return [
                'ok' => true,
                'message' => "Connected — {$response->model} answered in {$latency}ms.",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'latency_ms' => null];
        }
    }

    public function provider(): string
    {
        return $this->settings->get('llm.provider', 'anthropic');
    }

    public function modelFor(string $taskType): string
    {
        $model = $this->settings->get("llm.model.{$taskType}");

        if ($model) {
            return $model;
        }

        $default = config("llm.default_models.{$this->provider()}.{$taskType}");

        if (! $default) {
            throw new LlmException("No model configured for task type '{$taskType}' — set one in Admin → LLM Settings.");
        }

        return $default;
    }

    public function driver(): LlmDriver
    {
        $provider = $this->provider();
        $timeout = (int) $this->settings->get('llm.timeout', config('llm.defaults.timeout'));

        return match ($provider) {
            // Development/demo/E2E only. FakeDriver's own constructor refuses
            // production; it is also absent from the admin provider dropdown
            // (config llm.providers), so it can only be selected deliberately.
            'fake' => new FakeDriver,
            'anthropic' => new AnthropicDriver($this->requireKey('llm.anthropic.api_key', 'Anthropic'), $timeout),
            'openai' => new OpenAiDriver($this->requireKey('llm.openai.api_key', 'OpenAI'), $timeout),
            'openai_compatible' => new OpenAiCompatibleDriver(
                $this->settings->get('llm.compatible.api_key') ?? 'none',
                $timeout,
                rtrim($this->settings->get('llm.compatible.base_url', ''), '/')
                    ?: throw new LlmException('OpenAI-compatible base URL is not configured.'),
            ),
            default => throw new LlmException("Unknown LLM provider '{$provider}'."),
        };
    }

    protected function execute(string $taskType, ?string $meetingId, ?string $promptTemplateId, \Closure $call): LlmResponse
    {
        $driver = $this->driver();
        $model = $this->modelFor($taskType);
        $options = [
            'temperature' => (float) $this->settings->get('llm.temperature', config('llm.defaults.temperature')),
            'max_tokens' => (int) $this->settings->get('llm.max_tokens', config('llm.defaults.max_tokens')),
        ];

        $run = new GenerationRun([
            'meeting_id' => $meetingId,
            'prompt_template_id' => $promptTemplateId,
            'task_type' => $taskType,
            'provider' => $this->provider(),
            'model' => $model,
        ]);

        $start = hrtime(true);

        try {
            /** @var LlmResponse $response */
            $response = $call($driver, $model, $options);

            $run->fill([
                'status' => 'ok',
                'tokens_in' => $response->tokensIn,
                'tokens_out' => $response->tokensOut,
                'cost_estimate' => $this->estimateCost($response),
                'latency_ms' => (int) ((hrtime(true) - $start) / 1e6),
            ])->save();

            return $response;
        } catch (\Throwable $e) {
            $run->fill([
                'status' => 'error',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'latency_ms' => (int) ((hrtime(true) - $start) / 1e6),
            ])->save();

            throw $e;
        }
    }

    protected function estimateCost(LlmResponse $response): ?float
    {
        $pricing = config("llm.pricing.{$response->model}");

        if (! $pricing) {
            return null;
        }

        [$in, $out] = $pricing;

        return round(($response->tokensIn * $in + $response->tokensOut * $out) / 1_000_000, 6);
    }

    protected function requireKey(string $settingKey, string $label): string
    {
        $key = $this->settings->get($settingKey);

        if (! $key) {
            throw new LlmException("{$label} API key is not configured — set it in Admin → LLM Settings.");
        }

        return $key;
    }
}
