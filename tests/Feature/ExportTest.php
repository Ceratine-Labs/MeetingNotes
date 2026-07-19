<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesExporter;
use Modules\Minutes\Services\MinutesRenderer;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'U', 'email' => 'u@test.local', 'password' => 'x', 'role' => User::ROLE_USER,
        ]);
    }

    protected function readyMeeting(): Meeting
    {
        $sections = [
            'meeting_info' => [
                'title' => 'Q3 Planning Sync', 'date' => '2026-07-15', 'start_time' => '09:00',
                'end_time' => '10:30', 'duration' => '90 minutes', 'location' => 'Zoom',
                'meeting_type' => 'strategic', 'objective' => 'Agree Q3 priorities', 'chair' => 'Sarah Chen',
            ],
            'attendance' => [
                'present' => [['name' => 'Sarah Chen', 'title' => 'CTO', 'organization' => 'Acme']],
                'absent' => [['name' => 'Bob Marsh', 'reason' => 'annual leave']],
                'guests' => [],
            ],
            'discussion' => [[
                'heading' => 'Budget review',
                'summary' => "The team reviewed Q2 spend.\n\nSpend was 12% under budget.",
                'key_points' => [['point' => 'Cloud costs fell 8%', 'raised_by' => 'Sarah Chen']],
            ]],
            'decisions' => [[
                'ref' => 'D1', 'decision' => 'Reallocate savings', 'made_by' => 'Sarah Chen',
                'rationale' => 'Top OKR', 'conditions' => 'CFO sign-off', 'impact' => 'Earlier delivery',
            ]],
            'action_items' => [[
                'ref' => 'A1', 'description' => 'Prepare memo | with pipe', 'owner' => 'Sarah Chen',
                'due_date' => '2026-07-22', 'success_criteria' => 'Approved', 'priority' => 'high',
                'collaborators' => ['Finance'],
            ]],
            'parking_lot' => [['item' => 'Office move', 'type' => 'tabled', 'reason' => 'out of scope']],
            'supporting_materials' => [['title' => 'Q2 report', 'type' => 'spreadsheet']],
            'general_discussion' => [['topic' => 'Hiring', 'note' => 'Two offers out']],
            'next_steps' => ['next_meeting' => '2026-07-29', 'checkpoints' => ['Memo review'], 'communication_plan' => '#leadership', 'monitor' => ['Cloud costs']],
            'quality_notes' => '',
        ];

        $meeting = Meeting::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Q3 Planning Sync',
            'meeting_date' => '2026-07-15',
            'source_type' => 'paste',
            'status' => Meeting::STATUS_READY,
            'sections' => $sections,
            'rendered_html' => app(MinutesRenderer::class)->render($sections),
        ]);
        $meeting->transcript()->create(['raw_text' => 'src']);

        return $meeting;
    }

    public function test_markdown_export_contains_all_nine_sections(): void
    {
        $md = app(MinutesExporter::class)->markdown($this->readyMeeting());

        foreach (range(1, 9) as $n) {
            $this->assertStringContainsString("## {$n}. ", $md);
        }
        $this->assertStringContainsString('### D1 — Reallocate savings', $md);
        $this->assertStringContainsString('**A1**', $md);
        // Pipes in table cells must be escaped, not break the table.
        $this->assertStringContainsString('Prepare memo \\| with pipe', $md);
        $this->assertStringContainsString('**Sarah Chen**', $md);
    }

    public function test_markdown_download_has_headers(): void
    {
        $meeting = $this->readyMeeting();

        $this->actingAs($this->user)
            ->get("/app/minutes/{$meeting->id}/export/md")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertDownload('q3-planning-sync-2026-07-15.md');
    }

    public function test_pdf_export_produces_valid_pdf(): void
    {
        $meeting = $this->readyMeeting();

        $response = $this->actingAs($this->user)->get("/app/minutes/{$meeting->id}/export/pdf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_docx_export_produces_valid_zip_package(): void
    {
        $meeting = $this->readyMeeting();

        $response = $this->actingAs($this->user)->get("/app/minutes/{$meeting->id}/export/docx");

        $response->assertOk();
        // DOCX is a zip: PK magic bytes.
        $this->assertStringStartsWith('PK', $response->getContent());
        $this->assertGreaterThan(2000, strlen($response->getContent()));
    }

    public function test_export_blocked_for_unready_minutes_and_unknown_format(): void
    {
        $processing = Meeting::query()->create([
            'user_id' => $this->user->id,
            'source_type' => 'paste',
            'status' => Meeting::STATUS_PROCESSING,
        ]);

        $this->actingAs($this->user)->get("/app/minutes/{$processing->id}/export/pdf")->assertStatus(422);
        $this->actingAs($this->user)->get("/app/minutes/{$this->readyMeeting()->id}/export/xlsx")->assertNotFound();
    }
}
