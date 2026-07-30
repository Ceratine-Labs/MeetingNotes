<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Minutes\Models\Meeting;
use Modules\Search\Models\SearchDocument;
use Modules\Search\Services\SearchIndexer;
use Modules\Search\Services\SearchService;
use Modules\Tenancy\Models\Organisation;
use Modules\Tenancy\Services\OrganisationContext;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Workspace search.
 *
 * Note the engine difference: production runs PostgreSQL full-text search (stemming,
 * ranking, trigram prefix matching); these tests run on SQLite, where SearchService
 * falls back to LIKE. So the assertions here deliberately stick to behaviour both
 * engines share — a term that literally appears in the indexed text is found, and
 * results never cross a workspace boundary. Stemming ("budgets" matching "budget") is
 * PostgreSQL-only and is not asserted, because it would fail on SQLite for a reason
 * that has nothing to do with our code.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    /**
     * Build a realistic indexed meeting: a named attendee in the transcript, a decision
     * and an action item they own.
     */
    private function indexedMeeting(Organisation $organisation, string $person = 'Maria Ferreira'): Meeting
    {
        [$user] = [$organisation->memberships()->with('user')->first()->user, null];

        $meeting = Meeting::query()->create([
            'organisation_id' => $organisation->getKey(),
            'user_id' => $user->getKey(),
            'title' => 'Q3 Budget Review',
            'meeting_date' => '2026-07-15',
            'source_type' => 'paste',
            'status' => Meeting::STATUS_READY,
            'sections' => [
                'meeting_info' => ['title' => 'Q3 Budget Review', 'chair' => $person],
                'attendance' => ['present' => [['name' => $person, 'title' => 'Finance Director']]],
                'discussion' => [['heading' => 'Cloud spend', 'summary' => "{$person} walked the team through the cloud budget."]],
            ],
        ]);

        $meeting->transcript()->create([
            'raw_text' => "{$person}: Welcome everyone. Let us start with the cloud budget overrun in June.",
        ]);

        $meeting->decisions()->create([
            'ref' => 'D1',
            'decision' => 'Approved a reduced Q4 cloud budget',
            'made_by' => $person,
            'sort' => 0,
        ]);

        $meeting->actionItems()->create([
            'ref' => 'A1',
            'description' => 'Circulate the revised spreadsheet',
            'owner' => $person,
            'priority' => 'high',
            'sort' => 0,
        ]);

        app(SearchIndexer::class)->index($meeting->fresh(['transcript', 'decisions', 'actionItems']));

        return $meeting;
    }

    public function test_indexing_a_meeting_writes_a_document_per_searchable_part(): void
    {
        [, $org] = $this->tenantUser();
        $this->indexedMeeting($org);

        $documents = SearchDocument::withoutOrganisationScope()->get();

        // Transcript + sections + the decision + the action item.
        $this->assertGreaterThanOrEqual(4, $documents->count());
        $this->assertContains(SearchDocument::TYPE_TRANSCRIPT, $documents->pluck('type')->all());
        $this->assertContains(SearchDocument::TYPE_DECISION, $documents->pluck('type')->all());
        $this->assertContains(SearchDocument::TYPE_ACTION_ITEM, $documents->pluck('type')->all());
        $this->assertContains(SearchDocument::TYPE_SECTION, $documents->pluck('type')->all());
    }

    /**
     * The behaviour Ryan asked for: search a person's name, find the meeting they are
     * in — including via the raw transcript.
     */
    public function test_searching_a_persons_name_finds_their_meeting(): void
    {
        [, $org] = $this->tenantUser();
        $this->indexedMeeting($org, 'Maria Ferreira');

        $this->actingForOrganisation($org);

        $results = app(SearchService::class)->search('Maria');

        $this->assertNotEmpty($results);
        $this->assertContains(
            SearchDocument::TYPE_TRANSCRIPT,
            $results->pluck('type')->all(),
            'The transcript should match a name that only appears in the raw text.'
        );
    }

    /**
     * The single most important property here: search must never cross workspaces.
     */
    public function test_search_never_returns_another_workspaces_documents(): void
    {
        [, $orgA] = $this->tenantUser();
        [, $orgB] = $this->tenantUser();

        $this->indexedMeeting($orgA, 'Maria Ferreira');
        $this->indexedMeeting($orgB, 'Thabo Mahlangu');

        $this->actingForOrganisation($orgA);
        $forA = app(SearchService::class)->search('Thabo');
        $this->assertEmpty($forA, 'Workspace A must not see workspace B documents.');

        $this->actingForOrganisation($orgB);
        $forB = app(SearchService::class)->search('Maria');
        $this->assertEmpty($forB, 'Workspace B must not see workspace A documents.');
    }

    public function test_short_terms_are_not_queried(): void
    {
        [, $org] = $this->tenantUser();
        $this->indexedMeeting($org);
        $this->actingForOrganisation($org);

        // One character matches most of the corpus and is never useful.
        $this->assertEmpty(app(SearchService::class)->search('M'));
    }

    public function test_quick_results_group_by_meeting(): void
    {
        [, $org] = $this->tenantUser();
        $this->indexedMeeting($org, 'Maria Ferreira');
        $this->actingForOrganisation($org);

        $quick = app(SearchService::class)->quick('Maria');

        // One suggestion for the meeting, not one per matching document.
        $this->assertCount(1, $quick);
        $this->assertGreaterThan(1, $quick->first()['hits']);
    }

    public function test_reindexing_replaces_rather_than_duplicates(): void
    {
        [, $org] = $this->tenantUser();
        $meeting = $this->indexedMeeting($org);

        $before = SearchDocument::withoutOrganisationScope()->count();

        app(SearchIndexer::class)->index($meeting->fresh(['transcript', 'decisions', 'actionItems']));

        $this->assertSame(
            $before,
            SearchDocument::withoutOrganisationScope()->count(),
            'Re-indexing must delete-then-insert, not accumulate duplicate rows.'
        );
    }

    public function test_quick_endpoint_requires_authentication(): void
    {
        $this->get('/app/search/quick?q=Maria')->assertRedirect(route('auth.login'));
    }

    public function test_results_page_renders_matches(): void
    {
        [$user, $org] = $this->tenantUser();
        $this->indexedMeeting($org, 'Maria Ferreira');

        $this->actingAs($user)->get('/app/search?q=Maria')
            ->assertOk()
            ->assertSee('Q3 Budget Review');
    }

    protected function tearDown(): void
    {
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
