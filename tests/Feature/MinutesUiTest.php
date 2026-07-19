<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Minutes\Models\Meeting;
use Tests\TestCase;

class MinutesUiTest extends TestCase
{
    use RefreshDatabase;

    protected function user(): User
    {
        return User::query()->create([
            'name' => 'U',
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'role' => User::ROLE_USER,
        ]);
    }

    protected function meeting(User $user, array $attributes = []): Meeting
    {
        $meeting = Meeting::query()->create(array_merge([
            'user_id' => $user->id,
            'source_type' => 'paste',
            'status' => Meeting::STATUS_PROCESSING,
        ], $attributes));

        $meeting->transcript()->create(['raw_text' => 'Sarah: hello everyone…']);

        return $meeting;
    }

    public function test_library_renders_with_meetings_and_empty_state(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/app/minutes')
            ->assertOk()
            ->assertSee('No minutes yet');

        $this->meeting($user, ['title' => 'Q3 Sync', 'status' => Meeting::STATUS_READY]);

        $this->actingAs($user)->get('/app/minutes')
            ->assertOk()
            ->assertSee('Q3 Sync');
    }

    public function test_create_page_renders(): void
    {
        $this->actingAs($this->user())->get('/app/minutes/new')
            ->assertOk()
            ->assertSee('Paste')
            ->assertSee('Upload');
    }

    public function test_show_renders_processing_failed_and_ready_states(): void
    {
        $user = $this->user();

        $processing = $this->meeting($user, ['progress_stage' => 'extracting chunk 2/3']);
        $this->actingAs($user)->get("/app/minutes/{$processing->id}")
            ->assertOk()
            ->assertSee('Generating minutes')
            ->assertSee('extracting chunk 2/3');

        $failed = $this->meeting($user, ['status' => Meeting::STATUS_FAILED, 'error' => 'Anthropic API error 529']);
        $this->actingAs($user)->get("/app/minutes/{$failed->id}")
            ->assertOk()
            ->assertSee('Generation failed')
            ->assertSee('Anthropic API error 529')
            ->assertSee('Retry');

        $ready = $this->meeting($user, [
            'status' => Meeting::STATUS_READY,
            'title' => 'Board catch-up',
            'rendered_html' => '<article class="mn-minutes"><h2>1. Meeting Information</h2></article>',
        ]);
        $this->actingAs($user)->get("/app/minutes/{$ready->id}")
            ->assertOk()
            ->assertSee('Board catch-up')
            ->assertSee('1. Meeting Information', false);
    }

    public function test_retry_requeues_only_failed_meetings(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = $this->user();

        $ready = $this->meeting($user, ['status' => Meeting::STATUS_READY]);
        $this->actingAs($user)->post("/app/minutes/{$ready->id}/retry")->assertStatus(422);

        $failed = $this->meeting($user, ['status' => Meeting::STATUS_FAILED, 'error' => 'x']);
        $this->actingAs($user)->post("/app/minutes/{$failed->id}/retry")->assertRedirect();
        $this->assertSame('processing', $failed->fresh()->status);
    }
}
