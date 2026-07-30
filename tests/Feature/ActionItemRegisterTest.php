<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Minutes\Models\ActionItem;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Tenancy\Models\Organisation;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The cross-meeting action items register: listing, filtering, ticking items
 * off, tenant isolation, and the completion state surviving a projection
 * rebuild (the part that would silently regress if persist() forgot it).
 */
class ActionItemRegisterTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected ?Organisation $workspace = null;

    protected function user(): User
    {
        [$user, $organisation] = $this->tenantUser();
        $this->workspace = $organisation;

        return $user;
    }

    protected function readyMeetingWithItems(User $user, array $items, array $attributes = []): Meeting
    {
        $meeting = $this->tenantMeeting($user, $this->workspace, array_merge([
            'title' => 'Weekly Sync',
            'status' => Meeting::STATUS_READY,
        ], $attributes));

        foreach ($items as $i => $item) {
            $meeting->actionItems()->create($item + [
                'ref' => 'A' . ($i + 1),
                'description' => 'Do the thing ' . ($i + 1),
                'owner' => 'T. Mahlangu',
                'sort' => $i,
            ]);
        }

        return $meeting;
    }

    public function test_register_lists_open_items_across_meetings_and_defaults_to_open(): void
    {
        $user = $this->user();

        $this->readyMeetingWithItems($user, [
            ['description' => 'Circulate the revised budget'],
        ], ['title' => 'Budget Review']);

        $this->readyMeetingWithItems($user, [
            ['description' => 'Book the launch venue'],
            ['description' => 'Already handled item', 'status' => ActionItem::STATUS_DONE],
        ], ['title' => 'Launch Planning']);

        $response = $this->actingAs($user)->get('/app/action-items')
            ->assertOk()
            // Items from both meetings appear in one list.
            ->assertSee('Circulate the revised budget')
            ->assertSee('Book the launch venue')
            ->assertSee('Budget Review')
            ->assertSee('Launch Planning');

        // The default view answers "what is still owed": done items are hidden.
        $response->assertDontSee('Already handled item');

        // The done filter shows the audit trail.
        $this->actingAs($user)->get('/app/action-items?status=done')
            ->assertOk()
            ->assertSee('Already handled item')
            ->assertDontSee('Book the launch venue');
    }

    public function test_items_from_unready_meetings_are_not_listed(): void
    {
        $user = $this->user();

        $this->readyMeetingWithItems($user, [
            ['description' => 'Visible item'],
        ]);

        // A processing meeting's projection rows (partial state) stay out.
        $this->readyMeetingWithItems($user, [
            ['description' => 'Half-generated item'],
        ], ['status' => Meeting::STATUS_PROCESSING]);

        $this->actingAs($user)->get('/app/action-items')
            ->assertOk()
            ->assertSee('Visible item')
            ->assertDontSee('Half-generated item');
    }

    public function test_marking_done_and_reopening_stamps_and_clears_audit_fields(): void
    {
        $user = $this->user();
        $meeting = $this->readyMeetingWithItems($user, [['description' => 'Confirm the date']]);
        $item = $meeting->actionItems()->first();

        $this->actingAs($user)
            ->put("/app/action-items/{$item->id}", ['status' => 'done'])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame(ActionItem::STATUS_DONE, $item->status);
        $this->assertNotNull($item->completed_at);
        $this->assertSame($user->id, $item->completed_by);

        $this->actingAs($user)
            ->put("/app/action-items/{$item->id}", ['status' => 'open'])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame(ActionItem::STATUS_OPEN, $item->status);
        $this->assertNull($item->completed_at);
        $this->assertNull($item->completed_by);
    }

    public function test_another_workspaces_item_is_invisible_and_untouchable(): void
    {
        $user = $this->user();
        $meeting = $this->readyMeetingWithItems($user, [['description' => 'Private task']]);
        $item = $meeting->actionItems()->first();

        // A second, unrelated workspace.
        [$stranger] = $this->tenantUser();

        $this->actingAs($stranger)->get('/app/action-items')
            ->assertOk()
            ->assertDontSee('Private task');

        // The unscoped route binding resolves the row; the controller's
        // ownership check through the meeting relation must 404 it.
        $this->actingAs($stranger)
            ->put("/app/action-items/{$item->id}", ['status' => 'done'])
            ->assertNotFound();

        $this->assertSame(ActionItem::STATUS_OPEN, $item->fresh()->status);
    }

    public function test_completion_survives_projection_rebuild_by_ref(): void
    {
        $user = $this->user();
        $meeting = $this->readyMeetingWithItems($user, [
            ['description' => 'Send the report'],
            ['description' => 'Chase the invoice'],
        ]);

        $meeting->actionItems()->where('ref', 'A1')->first()->markDone($user->id);

        // Simulate a regeneration persisting the same document: A1 keeps its
        // tick (and its audit stamp), A2 stays open, and a brand-new A3 that
        // was not in the old projection starts open.
        $sections = [
            'action_items' => [
                ['ref' => 'A1', 'description' => 'Send the report', 'owner' => 'T. Mahlangu'],
                ['ref' => 'A2', 'description' => 'Chase the invoice', 'owner' => 'T. Mahlangu'],
                ['ref' => 'A3', 'description' => 'New follow-up', 'owner' => 'S. Naidoo'],
            ],
        ];

        app(MinutesGenerator::class)->persist($meeting, $sections);

        $items = $meeting->refresh()->actionItems()->get()->keyBy('ref');

        $this->assertSame(ActionItem::STATUS_DONE, $items['A1']->status);
        $this->assertSame($user->id, $items['A1']->completed_by);
        $this->assertSame(ActionItem::STATUS_OPEN, $items['A2']->status);
        $this->assertSame(ActionItem::STATUS_OPEN, $items['A3']->status);
    }
}
