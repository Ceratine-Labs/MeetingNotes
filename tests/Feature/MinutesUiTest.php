<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Minutes\Models\Meeting;
use Modules\Tenancy\Models\Organisation;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MinutesUiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    /**
     * The workspace every meeting in this test belongs to.
     *
     * Held on the instance because user() and meeting() have to agree about it — a
     * meeting created in a different workspace to the acting user is filtered out by
     * the organisation scope, and the assertions would then fail for a reason that has
     * nothing to do with the UI being tested.
     */
    protected ?Organisation $workspace = null;

    protected function user(): User
    {
        [$user, $organisation] = $this->tenantUser();
        $this->workspace = $organisation;

        return $user;
    }

    protected function meeting(User $user, array $attributes = []): Meeting
    {
        return $this->tenantMeeting($user, $this->workspace, $attributes);
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
            'rendered_html' => '<article class="mn-minutes"><h2>1. Meeting Information</h2></article>'
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
