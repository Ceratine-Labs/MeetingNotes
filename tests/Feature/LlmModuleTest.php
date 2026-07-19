<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Models\User;
use Modules\Core\Models\Setting;
use Modules\Llm\Models\GenerationRun;
use Modules\Llm\Models\PromptTemplate;
use Modules\Llm\Services\LlmManager;
use Tests\TestCase;

class LlmModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'a@test.local',
            'password' => 'x',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    protected function regularUser(): User
    {
        return User::query()->create([
            'name' => 'User',
            'email' => 'u@test.local',
            'password' => 'x',
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_llm_settings_require_admin_role(): void
    {
        $this->actingAs($this->regularUser())->get('/app/admin/llm')->assertForbidden();
        $this->actingAs($this->admin())->get('/app/admin/llm')->assertOk();
    }

    public function test_api_key_is_encrypted_at_rest_and_never_rendered(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/app/admin/llm', [
            'provider' => 'anthropic',
            'temperature' => 0.2,
            'max_tokens' => 8192,
            'timeout' => 300,
            'anthropic_api_key' => 'sk-ant-secret-test-key-1234',
        ])->assertRedirect();

        $row = Setting::query()->where('key', 'llm.anthropic.api_key')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString('sk-ant-secret-test-key-1234', $row->value);
        $this->assertSame('sk-ant-secret-test-key-1234', setting('llm.anthropic.api_key'));

        // The settings page must never echo the key — only the hint.
        $html = $this->actingAs($admin)->get('/app/admin/llm')->getContent();
        $this->assertStringNotContainsString('sk-ant-secret-test-key-1234', $html);
        $this->assertStringContainsString('ends in …1234', $html);
    }

    public function test_blank_key_field_keeps_stored_secret(): void
    {
        setting_service()->set('llm.anthropic.api_key', 'sk-ant-original', 'llm', encrypt: true);

        $this->actingAs($this->admin())->put('/app/admin/llm', [
            'provider' => 'anthropic',
            'temperature' => 0.3,
            'max_tokens' => 4096,
            'timeout' => 120,
            'anthropic_api_key' => '',
        ])->assertRedirect();

        $this->assertSame('sk-ant-original', setting('llm.anthropic.api_key'));
    }

    public function test_prompt_editing_publishes_new_version_and_keeps_history(): void
    {
        $v1 = PromptTemplate::query()->create([
            'name' => 'minutes.generate',
            'version' => 1,
            'body' => 'original body',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post("/app/admin/prompts/{$v1->id}/versions", ['body' => 'updated body'])
            ->assertRedirect();

        $this->assertSame(2, PromptTemplate::query()->where('name', 'minutes.generate')->count());
        $this->assertSame('original body', $v1->fresh()->body);
        $this->assertFalse($v1->fresh()->is_active);

        $active = PromptTemplate::active('minutes.generate');
        $this->assertSame(2, $active->version);
        $this->assertSame('updated body', $active->body);
    }

    public function test_structured_call_parses_tool_use_and_logs_run_with_cost(): void
    {
        setting_service()->set('llm.provider', 'anthropic', 'llm');
        setting_service()->set('llm.anthropic.api_key', 'sk-test', 'llm', encrypt: true);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'tool_use', 'name' => 'emit_result', 'input' => ['title' => 'Weekly Sync']],
                ],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]),
        ]);

        $response = app(LlmManager::class)->structured(
            'generate_full',
            'system prompt',
            'transcript',
            ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
        );

        $this->assertSame(['title' => 'Weekly Sync'], $response->content);

        $run = GenerationRun::query()->sole();
        $this->assertSame('ok', $run->status);
        $this->assertSame(1000, $run->tokens_in);
        $this->assertSame(500, $run->tokens_out);
        // 1000 * $3/M + 500 * $15/M
        $this->assertEqualsWithDelta(0.0105, $run->cost_estimate, 0.0001);
    }

    public function test_provider_error_is_logged_as_failed_run(): void
    {
        setting_service()->set('llm.provider', 'anthropic', 'llm');
        setting_service()->set('llm.anthropic.api_key', 'sk-test', 'llm', encrypt: true);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401),
        ]);

        try {
            app(LlmManager::class)->complete('generate_full', 'sys', 'user');
            $this->fail('expected LlmException');
        } catch (\Modules\Llm\Exceptions\LlmException) {
            // expected
        }

        $run = GenerationRun::query()->sole();
        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('invalid x-api-key', $run->error);
    }

    public function test_test_connection_reports_failure_without_throwing(): void
    {
        setting_service()->set('llm.provider', 'anthropic', 'llm');
        setting_service()->set('llm.anthropic.api_key', 'sk-bad', 'llm', encrypt: true);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'nope']], 401),
        ]);

        $result = app(LlmManager::class)->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nope', $result['message']);
    }
}
