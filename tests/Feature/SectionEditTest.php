<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Models\User;
use Modules\Minutes\Jobs\RegenerateSectionJob;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Tenancy\Models\Organisation;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SectionEditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    protected User $user;

    /** The workspace the edited meetings belong to. */
    protected Organisation $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        setting_service()->set('llm.provider', 'anthropic', 'llm');
        setting_service()->set('llm.anthropic.api_key', 'sk-test', 'llm', encrypt: true);
        app(\Modules\Llm\Database\Seeders\PromptTemplateSeeder::class)->run();

        [$this->user, $this->workspace] = $this->tenantUser();
    }

    protected function readyMeeting(): Meeting
    {
        $sections = [
            'meeting_info' => ['title' => 'Sync', 'date' => '2026-07-15', 'meeting_type' => 'regular', 'objective' => 'Weekly review'],
            'attendance' => ['present' => [['name' => 'Sarah']]],
            'discussion' => [],
            'decisions' => [['ref' => 'D1', 'decision' => 'Original decision']],
            'action_items' => [['ref' => 'A1', 'description' => 'Original action', 'owner' => 'Sarah', 'priority' => 'low']],
            'parking_lot' => [],
            'supporting_materials' => [],
            'general_discussion' => [],
            'next_steps' => [],
            'quality_notes' => ''
        ];

        $meeting = Meeting::query()->create([
            // Without this the row belongs to no workspace and the
            // organisation scope hides it from the acting user.
            'organisation_id' => $this->workspace->getKey(),
            'user_id' => $this->user->id,
            'source_type' => 'paste',
            'status' => Meeting::STATUS_READY,
            'sections' => $sections,
            'rendered_html' => app(\Modules\Minutes\Services\MinutesRenderer::class)->render($sections)
        ]);
        $meeting->transcript()->create(['raw_text' => 'Sarah: original transcript…']);
        $meeting->decisions()->create(['ref' => 'D1', 'decision' => 'Original decision', 'sort' => 0]);
        $meeting->actionItems()->create(['ref' => 'A1', 'description' => 'Original action', 'owner' => 'Sarah', 'priority' => 'low', 'sort' => 0]);

        return $meeting;
    }

    public function test_manual_section_edit_rebuilds_rows_and_html(): void
    {
        $meeting = $this->readyMeeting();

        $newActions = [
            ['ref' => 'A1', 'description' => 'Edited action', 'owner' => 'Bob', 'priority' => 'high'],
            ['ref' => 'A2', 'description' => 'Added action', 'owner' => 'Sarah', 'priority' => 'medium']
        ];

        $this->actingAs($this->user)
            ->putJson("/app/minutes/{$meeting->id}/sections/action_items", [
                'value' => json_encode($newActions)
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $meeting->refresh();
        $this->assertCount(2, $meeting->actionItems);
        $this->assertSame('Bob', $meeting->actionItems->first()->owner);
        $this->assertStringContainsString('Edited action', $meeting->rendered_html);
        $this->assertStringContainsString('Added action', $meeting->rendered_html);
    }

    public function test_invalid_edit_is_rejected_with_problems(): void
    {
        $meeting = $this->readyMeeting();

        $this->actingAs($this->user)
            ->putJson("/app/minutes/{$meeting->id}/sections/action_items", [
                'value' => json_encode([['description' => 'missing ref and owner']])
            ])
            ->assertStatus(422);

        $this->assertSame('Original action', $meeting->fresh()->actionItems->first()->description);
    }

    public function test_unknown_section_is_404(): void
    {
        $meeting = $this->readyMeeting();

        $this->actingAs($this->user)
            ->putJson("/app/minutes/{$meeting->id}/sections/nonsense", ['value' => '[]'])
            ->assertNotFound();
    }

    public function test_regenerate_dispatches_job_and_blocks_double_run(): void
    {
        Queue::fake();
        $meeting = $this->readyMeeting();

        $this->actingAs($this->user)
            ->postJson("/app/minutes/{$meeting->id}/sections/decisions/regenerate")
            ->assertOk();

        Queue::assertPushed(RegenerateSectionJob::class);
        $this->assertSame('decisions', $meeting->fresh()->regen_section);

        $this->actingAs($this->user)
            ->postJson("/app/minutes/{$meeting->id}/sections/decisions/regenerate")
            ->assertStatus(422);
    }

    public function test_regeneration_job_stores_proposal_without_touching_minutes(): void
    {
        $meeting = $this->readyMeeting();
        $meeting->update(['regen_section' => 'decisions']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'name' => 'emit_result', 'input' => [
                    'decisions' => [['ref' => 'D1', 'decision' => 'Regenerated decision', 'made_by' => 'Sarah']]
                ]]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50]
            ])
        ]);

        (new RegenerateSectionJob($meeting->id, 'decisions', $this->workspace->getKey()))->handle(app(MinutesGenerator::class));

        $meeting->refresh();
        $this->assertNull($meeting->regen_section);
        $this->assertSame('decisions', $meeting->section_proposal['section']);
        $this->assertSame('Regenerated decision', $meeting->section_proposal['value'][0]['decision']);
        // Minutes untouched until accept.
        $this->assertSame('Original decision', $meeting->decisions->first()->decision);
        $this->assertStringContainsString('Original decision', $meeting->rendered_html);
    }

    public function test_accept_applies_proposal_and_rerenders(): void
    {
        $meeting = $this->readyMeeting();
        $meeting->update(['section_proposal' => [
            'section' => 'decisions',
            'value' => [['ref' => 'D1', 'decision' => 'Accepted decision', 'made_by' => 'Sarah']]
        ]]);

        $this->actingAs($this->user)
            ->postJson("/app/minutes/{$meeting->id}/proposal/accept")
            ->assertOk();

        $meeting->refresh();
        $this->assertNull($meeting->section_proposal);
        $this->assertSame('Accepted decision', $meeting->decisions->first()->decision);
        $this->assertStringContainsString('Accepted decision', $meeting->rendered_html);
    }

    public function test_discard_clears_proposal_and_changes_nothing(): void
    {
        $meeting = $this->readyMeeting();
        $meeting->update(['section_proposal' => [
            'section' => 'decisions',
            'value' => [['ref' => 'D1', 'decision' => 'Should never apply']]
        ]]);

        $this->actingAs($this->user)
            ->postJson("/app/minutes/{$meeting->id}/proposal/discard")
            ->assertOk();

        $meeting->refresh();
        $this->assertNull($meeting->section_proposal);
        $this->assertSame('Original decision', $meeting->decisions->first()->decision);
    }

    public function test_proposal_diff_returns_current_and_proposed_html(): void
    {
        $meeting = $this->readyMeeting();
        $meeting->update(['section_proposal' => [
            'section' => 'decisions',
            'value' => [['ref' => 'D1', 'decision' => 'Proposed decision']]
        ]]);

        $response = $this->actingAs($this->user)
            ->getJson("/app/minutes/{$meeting->id}/proposal")
            ->assertOk()
            ->json();

        $this->assertSame('decisions', $response['section']);
        $this->assertStringContainsString('Original decision', $response['current_html']);
        $this->assertStringContainsString('Proposed decision', $response['proposed_html']);
    }
}
