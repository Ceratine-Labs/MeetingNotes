<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Models\User;
use Modules\Llm\Models\GenerationRun;
use Modules\Minutes\Jobs\GenerateMinutesJob;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Minutes\Support\MinutesSchema;
use Modules\Tenancy\Models\Organisation;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MinutesPipelineTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    protected function setUp(): void
    {
        parent::setUp();

        setting_service()->set('llm.provider', 'anthropic', 'llm');
        setting_service()->set('llm.anthropic.api_key', 'sk-test', 'llm', encrypt: true);

        // Prompt templates normally arrive via seed master.
        app(\Modules\Llm\Database\Seeders\PromptTemplateSeeder::class)->run();
    }

    protected ?User $testUser = null;

    protected ?Organisation $testWorkspace = null;

    /**
     * The test's user, created once and reused.
     *
     * Memoised deliberately. It previously minted a fresh user on every call, which was
     * harmless when the app was single-tenant — but now each user gets their own
     * workspace, so `actingAs($this->user())` would be a *different* tenant to the one
     * that owns the meeting fixture, and the organisation scope would hide it. The
     * failure would look like a pipeline bug rather than a fixture bug.
     */
    protected function user(): User
    {
        if ($this->testUser === null) {
            [$this->testUser, $this->testWorkspace] = $this->tenantUser();
        }

        return $this->testUser;
    }

    /**
     * The workspace that user owns.
     */
    protected function workspace(): Organisation
    {
        $this->user();

        return $this->testWorkspace;
    }

    protected function validSections(): array
    {
        return [
            'meeting_info' => [
                'title' => 'Q3 Planning Sync',
                'date' => '2026-07-15',
                'start_time' => '09:00',
                'end_time' => '10:30',
                'duration' => '90 minutes',
                'location' => 'Zoom',
                'meeting_type' => 'strategic',
                'objective' => 'Agree Q3 priorities',
                'chair' => 'Sarah Chen'
            ],
            'attendance' => [
                'present' => [['name' => 'Sarah Chen', 'title' => 'CTO', 'organization' => 'Acme']],
                'absent' => [['name' => 'Bob Marsh', 'reason' => 'annual leave']],
                'guests' => []
            ],
            'discussion' => [[
                'heading' => 'Budget review',
                'summary' => "The team reviewed Q2 spend.\n\nSpend was 12% under budget.",
                'key_points' => [['point' => 'Cloud costs fell 8%', 'raised_by' => 'Sarah Chen']],
                'questions' => [['question' => 'Can we reallocate savings?', 'answer' => 'Yes, pending CFO sign-off.']],
                'data_points' => ['Q2 spend: $84k of $96k budget'],
                'unresolved' => ['CFO sign-off timing']
            ]],
            'decisions' => [[
                'ref' => 'D1',
                'decision' => 'Reallocate Q2 savings to the mobile rewrite',
                'made_by' => 'Sarah Chen',
                'rationale' => 'Mobile is the top OKR',
                'conditions' => 'CFO sign-off',
                'impact' => 'Rewrite lands one sprint earlier'
            ]],
            'action_items' => [[
                'ref' => 'A1',
                'description' => 'Prepare reallocation memo for CFO',
                'owner' => 'Sarah Chen',
                'due_date' => '2026-07-22',
                'success_criteria' => 'Memo approved',
                'dependencies' => 'Q2 final numbers',
                'priority' => 'high',
                'collaborators' => ['Finance team']
            ]],
            'parking_lot' => [['item' => 'Office move', 'type' => 'tabled', 'reason' => 'out of scope']],
            'supporting_materials' => [['title' => 'Q2 spend report', 'type' => 'spreadsheet', 'reference' => 'Drive']],
            'general_discussion' => [['topic' => 'Hiring', 'note' => 'Two offers out']],
            'next_steps' => [
                'next_meeting' => '2026-07-29 09:00',
                'checkpoints' => ['Memo review on 2026-07-22'],
                'communication_plan' => 'Summary to #leadership',
                'monitor' => ['Cloud cost trend']
            ],
            'quality_notes' => ''
        ];
    }

    protected function anthropicResponse(array $input, int $in = 5000, int $out = 2000): array
    {
        return [
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'tool_use', 'name' => 'emit_result', 'input' => $input]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out]
        ];
    }

    protected function makeMeeting(string $text, ?int $tokenEstimate = null): Meeting
    {
        // user() first: it is what creates the workspace, and the array below is
        // evaluated top-down.
        $user = $this->user();

        $meeting = Meeting::query()->create([
            // Without this the row belongs to no workspace and the organisation scope
            // hides it from the acting user.
            'organisation_id' => $this->workspace()->getKey(),
            'user_id' => $user->id,
            'source_type' => 'paste',
            'status' => Meeting::STATUS_PROCESSING
        ]);

        $meeting->transcript()->create([
            'raw_text' => $text,
            'word_count' => str_word_count($text),
            'token_estimate' => $tokenEstimate ?? (int) ceil(mb_strlen($text) / 4)
        ]);

        return $meeting;
    }

    public function test_store_paste_creates_meeting_and_dispatches_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user())->post('/app/minutes', [
            'mode' => 'paste',
            'pasted_text' => str_repeat('Sarah: we should review the budget. ', 20),
            'title' => 'Budget chat'
        ])->assertRedirect();

        $meeting = Meeting::query()->sole();
        $this->assertSame('processing', $meeting->status);
        $this->assertSame('Budget chat', $meeting->title);
        $this->assertNotNull($meeting->transcript);

        Queue::assertPushed(GenerateMinutesJob::class, fn ($job) => $job->meetingId === $meeting->id);
    }

    public function test_too_short_paste_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->post('/app/minutes', ['mode' => 'paste', 'pasted_text' => 'hi'])
            ->assertSessionHasErrors('pasted_text');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_unsupported_upload_is_rejected(): void
    {
        $file = \Illuminate\Http\Testing\File::create('meeting.mp3', 100);

        $this->actingAs($this->user())
            ->post('/app/minutes', ['mode' => 'upload', 'file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_pipeline_generates_ready_minutes_with_child_rows_and_html(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicResponse($this->validSections()))]);

        $meeting = $this->makeMeeting('Sarah: welcome to the Q3 planning sync…');

        (new GenerateMinutesJob($meeting->id, $this->workspace()->getKey()))->handle(app(MinutesGenerator::class));

        $meeting->refresh();
        $this->assertSame('ready', $meeting->status);
        $this->assertSame('Q3 Planning Sync', $meeting->title);
        $this->assertSame('2026-07-15', $meeting->meeting_date->toDateString());
        $this->assertSame('claude-sonnet-4-6', $meeting->model_used);
        $this->assertSame(1, $meeting->prompt_version);

        // Typed projections.
        $this->assertSame(1, $meeting->decisions()->count());
        $this->assertSame('D1', $meeting->decisions->first()->ref);
        $this->assertSame(1, $meeting->actionItems()->count());
        $this->assertSame('high', $meeting->actionItems->first()->priority);

        // Canonical render.
        $html = $meeting->rendered_html;
        foreach (['1. Meeting Information', '2. Attendance', '3. Discussion Summary',
            '4. Decisions', '5. Action Items', '6. Parking Lot', '7. Supporting Materials',
            '8. General Discussion', '9. Next Steps'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
        $this->assertStringContainsString('D1', $html);
        $this->assertStringContainsString('Prepare reallocation memo', $html);
        $this->assertStringContainsString('Sarah Chen', $html);
    }

    public function test_pipeline_repairs_invalid_structure_once(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse(['meeting_info' => ['title' => 'broken']]))
                ->push($this->anthropicResponse($this->validSections()))
        ]);

        $meeting = $this->makeMeeting('short transcript…');

        (new GenerateMinutesJob($meeting->id, $this->workspace()->getKey()))->handle(app(MinutesGenerator::class));

        $this->assertSame('ready', $meeting->fresh()->status);
        $this->assertSame(2, GenerationRun::query()->where('task_type', 'generate_full')->count());
    }

    public function test_pipeline_fails_cleanly_when_repair_also_fails(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse(['garbage' => true]))
        ]);

        $meeting = $this->makeMeeting('short transcript…');
        $job = new GenerateMinutesJob($meeting->id, $this->workspace()->getKey());

        try {
            $job->handle(app(MinutesGenerator::class));
            $this->fail('expected GenerationException');
        } catch (\Modules\Minutes\Services\GenerationException $e) {
            $job->failed($e); // what the queue worker would do after retries
        }

        $meeting->refresh();
        $this->assertSame('failed', $meeting->status);
        $this->assertStringContainsString('failed validation', $meeting->error);
    }

    public function test_long_transcript_takes_map_reduce_path(): void
    {
        $chunkResponse = $this->anthropicResponse([
            'facts' => [['topic' => 'budget', 'detail' => 'under by 12%', 'said_by' => 'Sarah']]
        ], 1000, 200);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($chunkResponse)
                ->push($chunkResponse)
                ->push($chunkResponse)
                ->push($this->anthropicResponse($this->validSections()))
        ]);

        // ~200k chars => token estimate ~50k > 30k budget => 3 chunks of 80k.
        $meeting = $this->makeMeeting(str_repeat("Sarah: line of discussion.\n", 7500));

        (new GenerateMinutesJob($meeting->id, $this->workspace()->getKey()))->handle(app(MinutesGenerator::class));

        $this->assertSame('ready', $meeting->fresh()->status);
        $this->assertGreaterThanOrEqual(2, GenerationRun::query()->where('task_type', 'chunk_map')->count());
        $this->assertSame(1, GenerationRun::query()->where('task_type', 'chunk_reduce')->count());
    }

    public function test_chunking_covers_all_text_with_overlap(): void
    {
        $generator = app(MinutesGenerator::class);
        $text = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 12000)));

        $chunks = $generator->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        // First line of every subsequent chunk must exist in the overlap
        // region of the text — i.e. nothing was skipped.
        $rebuilt = $chunks[0];
        foreach (array_slice($chunks, 1) as $chunk) {
            $head = mb_substr($chunk, 0, 200);
            $this->assertStringContainsString(mb_substr($head, 0, 40), $rebuilt . $chunk);
            $rebuilt .= $chunk;
        }

        $this->assertStringContainsString('line 1', $chunks[0]);
        $this->assertStringContainsString('line 12000', end($chunks));
    }

    public function test_status_endpoint_reports_progress(): void
    {
        $meeting = $this->makeMeeting('text…');
        $meeting->update(['progress_stage' => 'merging']);

        $this->actingAs($this->user())
            ->get("/app/minutes/{$meeting->id}/status")
            ->assertOk()
            ->assertJson(['status' => 'processing', 'progress_stage' => 'merging']);
    }

    public function test_schema_validate_catches_shape_breakage(): void
    {
        $this->assertSame([], MinutesSchema::validate($this->validSections()));

        $broken = $this->validSections();
        unset($broken['action_items']);
        $broken['decisions'] = 'not an array';

        $problems = MinutesSchema::validate($broken);
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString("missing section 'action_items'", implode('; ', $problems));
    }
}
