<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Minutes\Jobs\GenerateMinutesJob;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The FakeDriver is what the demo seeder and the E2E suite stand on, so
 * its guarantees get their own tests: a full pipeline run with no HTTP
 * fakes and no network, and the production refusal.
 */
class FakeLlmDriverTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        setting_service()->set('llm.provider', 'fake', 'llm');

        // Prompt templates normally arrive via seed master.
        app(\Modules\Llm\Database\Seeders\PromptTemplateSeeder::class)->run();
    }

    public function test_full_pipeline_produces_ready_minutes_without_any_http(): void
    {
        // No Http::fake() anywhere in this test: a stray outbound request
        // would be a real request and fail loudly in CI, which is the point.
        [$user, $organisation] = $this->tenantUser();

        $meeting = $this->tenantMeeting($user, $organisation);
        $meeting->transcript()->update([
            'word_count' => 40,
            'token_estimate' => 60,
        ]);

        (new GenerateMinutesJob($meeting->id, $organisation->getKey()))
            ->handle(app(MinutesGenerator::class));

        $meeting->refresh();
        $this->assertSame(Meeting::STATUS_READY, $meeting->status);
        $this->assertSame('fake', $meeting->model_used);

        // The canned document is complete: nine sections, child rows, HTML.
        $this->assertNotNull($meeting->sections['quality_notes'] ?? null);
        $this->assertGreaterThan(0, $meeting->decisions()->count());
        $this->assertSame(3, $meeting->actionItems()->count());
        $this->assertStringContainsString('9. Next Steps', $meeting->rendered_html);
    }

    public function test_fake_driver_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(\Modules\Llm\Exceptions\LlmException::class);
            new \Modules\Llm\Drivers\FakeDriver;
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
