<?php

namespace Modules\Search\Services;

use Illuminate\Support\Facades\DB;
use Modules\Minutes\Models\Meeting;
use Modules\Search\Models\SearchDocument;

/**
 * Builds and maintains the search index for a meeting.
 *
 * Called whenever a meeting's minutes are persisted (initial generation, section edit,
 * accepted regeneration), and by `php artisan search:reindex` for backfills.
 *
 * The strategy is **delete-then-insert per meeting**, never incremental updates. A
 * regeneration can add, remove or renumber decisions and action items, so working out
 * which index rows changed is both fiddly and a source of orphans. Rebuilding one
 * meeting's rows is a handful of queries and cannot drift.
 */
class SearchIndexer
{
    /**
     * Rebuild the index for one meeting.
     *
     * Wrapped in a transaction so a failure part-way cannot leave the meeting with the
     * old rows deleted and the new ones missing — which would silently remove it from
     * search until the next regeneration.
     *
     * @return int How many documents were written.
     */
    public function index(Meeting $meeting): int
    {
        // A meeting with no workspace cannot be searched by anyone (search is
        // organisation-scoped), so indexing it would only create unreachable rows.
        if (empty($meeting->organisation_id)) {
            return 0;
        }

        return DB::transaction(function () use ($meeting): int {
            $this->forget($meeting);

            $documents = array_merge(
                $this->transcriptDocuments($meeting),
                $this->sectionDocuments($meeting),
                $this->decisionDocuments($meeting),
                $this->actionItemDocuments($meeting),
            );

            foreach ($documents as $document) {
                SearchDocument::query()->create($document);
            }

            return count($documents);
        });
    }

    /**
     * Drop a meeting's index rows.
     *
     * Uses withoutOrganisationScope with an explicit meeting_id: this runs from queued
     * jobs, console commands and model deletion hooks where no organisation is bound,
     * and the meeting id already confines the delete to one workspace's data.
     */
    public function forget(Meeting $meeting): void
    {
        SearchDocument::withoutOrganisationScope()
            ->where('meeting_id', $meeting->getKey())
            ->delete();
    }

    /**
     * The raw meeting text.
     *
     * This is the row that makes "search Maria and find the transcript she is in" work
     * — names of attendees who are never named in a decision still appear here.
     *
     * @return list<array<string, mixed>>
     */
    private function transcriptDocuments(Meeting $meeting): array
    {
        $transcript = $meeting->transcript;

        if ($transcript === null || trim((string) $transcript->raw_text) === '') {
            return [];
        }

        return [$this->document($meeting, SearchDocument::TYPE_TRANSCRIPT, [
            'title' => $this->meetingTitle($meeting),
            'label' => 'Transcript',
            'body' => $transcript->raw_text,
        ])];
    }

    /**
     * One document per populated minutes section.
     *
     * Sections are indexed individually rather than as one blob so a result can link
     * the reader to the part of the document that matched, and so a match in
     * "Decisions" outranks the same word in a long discussion narrative.
     *
     * @return list<array<string, mixed>>
     */
    private function sectionDocuments(Meeting $meeting): array
    {
        $sections = $meeting->sections;

        if (! is_array($sections)) {
            return [];
        }

        // Canonical section keys mapped to their human headings (docs/PRODUCT_SPEC.md).
        // Decisions and action items are excluded: they get their own, better-targeted
        // documents from the typed child rows below.
        $headings = [
            'meeting_info' => 'Meeting information',
            'attendance' => 'Attendance',
            'discussion' => 'Discussion summary',
            'parking_lot' => 'Parking lot',
            'supporting_materials' => 'Supporting materials',
            'general_discussion' => 'General discussion',
            'next_steps' => 'Next steps',
            'quality_notes' => 'Quality notes',
        ];

        $documents = [];

        foreach ($headings as $key => $heading) {
            $body = $this->flatten($sections[$key] ?? null);

            if ($body === '') {
                continue;
            }

            $documents[] = $this->document($meeting, SearchDocument::TYPE_SECTION, [
                'title' => $this->meetingTitle($meeting).' — '.$heading,
                'label' => $heading,
                'body' => $body,
            ]);
        }

        return $documents;
    }

    /**
     * One document per decision, so "what did we decide about the budget" lands on the
     * decision itself rather than on the transcript around it.
     *
     * @return list<array<string, mixed>>
     */
    private function decisionDocuments(Meeting $meeting): array
    {
        return $meeting->decisions->map(fn ($decision) => $this->document(
            $meeting,
            SearchDocument::TYPE_DECISION,
            [
                'title' => $this->meetingTitle($meeting).' — '.$decision->ref,
                'label' => 'Decision '.$decision->ref,
                'source_id' => $decision->getKey(),
                // Every field a person might search: the decision, who made it, why,
                // and the caveats.
                'body' => implode("\n", array_filter([
                    $decision->decision,
                    $decision->made_by,
                    $decision->rationale,
                    $decision->conditions,
                    $decision->impact,
                ])),
            ]
        ))->all();
    }

    /**
     * One document per action item — including the owner's name, so searching a person
     * finds what they are on the hook for.
     *
     * @return list<array<string, mixed>>
     */
    private function actionItemDocuments(Meeting $meeting): array
    {
        return $meeting->actionItems->map(fn ($action) => $this->document(
            $meeting,
            SearchDocument::TYPE_ACTION_ITEM,
            [
                'title' => $this->meetingTitle($meeting).' — '.$action->ref,
                'label' => 'Action '.$action->ref.($action->owner ? ' · '.$action->owner : ''),
                'source_id' => $action->getKey(),
                'body' => implode("\n", array_filter([
                    $action->description,
                    $action->owner,
                    $action->due_date,
                    $action->success_criteria,
                    $action->dependencies,
                ])),
            ]
        ))->all();
    }

    /**
     * Assemble a document row, filling in the fields every type shares.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function document(Meeting $meeting, string $type, array $attributes): array
    {
        return array_merge([
            'organisation_id' => $meeting->organisation_id,
            'type' => $type,
            'meeting_id' => $meeting->getKey(),
            'source_id' => null,
            'weight' => SearchDocument::WEIGHTS[$type] ?? 50,
            'meeting_date' => $meeting->meeting_date ?? $meeting->created_at,
        ], $attributes);
    }

    /**
     * A meeting's display title, with a fallback.
     *
     * Untitled meetings are common — the generator infers a title from the transcript
     * and cannot always find one — and a result row with a blank heading is unusable.
     */
    private function meetingTitle(Meeting $meeting): string
    {
        return $meeting->title ?: 'Untitled meeting';
    }

    /**
     * Flatten an arbitrarily nested section value into searchable text.
     *
     * Sections vary in shape: a string, a flat map, or a list of maps with nested lists
     * (the discussion section). Rather than a bespoke extractor per section — eight
     * more things to keep in step with the schema — this walks whatever it is given and
     * keeps the scalars.
     *
     * Keys are dropped deliberately: nobody searches for "raised_by", and including
     * field names would pollute the full-text vector with terms that match everything.
     */
    private function flatten(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (is_scalar($value)) {
            // "[Not specified]" is the generator's marker for a gap. Indexing it would
            // make that phrase match nearly every meeting.
            $string = trim((string) $value);

            return $string === '[Not specified]' ? '' : $string;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $item) {
            $flattened = $this->flatten($item);

            if ($flattened !== '') {
                $parts[] = $flattened;
            }
        }

        return implode("\n", $parts);
    }
}
