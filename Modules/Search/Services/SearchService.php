<?php

namespace Modules\Search\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Search\Models\SearchDocument;

/**
 * Runs workspace search queries.
 *
 * Two engines, chosen by database driver:
 *
 * **PostgreSQL** — full-text search over the generated `tsvector` column, ranked with
 * `ts_rank`, plus a trigram/ILIKE prefix arm so a partial word ("Mar") still finds
 * "Maria". Full-text search alone matches whole lexemes, so without that second arm
 * type-ahead would return nothing until the user finished the word — which is exactly
 * the wrong behaviour for a search-as-you-type box.
 *
 * **Anything else** (SQLite, in tests) — LIKE matching, ordered by document weight.
 * Genuinely worse: no stemming, no relevance. A deliberate trade so the wiring is
 * testable without requiring PostgreSQL in the test environment.
 *
 * Results are organisation-scoped automatically by the SearchDocument model, so there
 * is no `where organisation_id` anywhere in this class — and no way to forget it.
 */
class SearchService
{
    /**
     * Shortest term worth running.
     *
     * One or two characters match most of the corpus, which is slow and useless. The UI
     * enforces the same minimum, so this is the backstop for a direct request.
     */
    public const MIN_TERM_LENGTH = 2;

    /**
     * Cap for the navbar type-ahead dropdown.
     */
    public const QUICK_LIMIT = 8;

    /**
     * Search the current workspace.
     *
     * @param  string  $term  Raw user input. Never interpolated into SQL — bound as a
     *         parameter in every branch below.
     * @param  int  $limit  Maximum results.
     * @param  string|null  $type  Restrict to one SearchDocument::TYPE_* value.
     * @return Collection<int, SearchDocument>
     */
    public function search(string $term, int $limit = 25, ?string $type = null): Collection
    {
        $term = $this->normalise($term);

        if ($term === null) {
            return collect();
        }

        $query = SearchDocument::query()
            ->with('meeting')
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type));

        return DB::connection()->getDriverName() === 'pgsql'
            ? $this->searchPostgres($query, $term, $limit)
            : $this->searchFallback($query, $term, $limit);
    }

    /**
     * Results for the navbar dropdown, grouped by meeting.
     *
     * Grouped because a single meeting often matches several ways at once (transcript,
     * a decision, an action item), and eight rows all pointing at the same meeting is a
     * useless dropdown. One entry per meeting, with the best-matching hit as the
     * preview, is what someone scanning a type-ahead list actually wants.
     *
     * @return Collection<int, array{
     *     meeting_id: string,
     *     title: string,
     *     url: string,
     *     date: string|null,
     *     best: SearchDocument,
     *     hits: int,
     *     types: list<string>
     * }>
     */
    public function quick(string $term): Collection
    {
        // Over-fetch, because collapsing by meeting reduces the count — asking for 8
        // rows would routinely yield 3 suggestions.
        $documents = $this->search($term, self::QUICK_LIMIT * 4);

        return $documents
            ->groupBy('meeting_id')
            ->map(function (Collection $group) use ($term): array {
                /** @var SearchDocument $best */
                $best = $group->first();

                return [
                    'meeting_id' => $best->meeting_id,
                    'title' => $best->meeting?->title ?: 'Untitled meeting',
                    'url' => route('minutes.show', $best->meeting_id),
                    'date' => $best->meeting_date?->toFormattedDayDateString(),
                    'best' => $best,
                    'snippet' => $best->snippet($term),
                    'hits' => $group->count(),
                    // Distinct badges, so a reader can see at a glance that a meeting
                    // matched on a decision as well as in the transcript.
                    'types' => $group->pluck('type')->unique()->values()->all(),
                ];
            })
            ->take(self::QUICK_LIMIT)
            ->values();
    }

    /**
     * PostgreSQL: ranked full-text search, with a prefix arm for partial words.
     *
     * `websearch_to_tsquery` rather than `plainto_tsquery` because it understands
     * quoted phrases and `-exclusions` the way a user expects from a search box, and it
     * never throws on odd punctuation — `to_tsquery` does, on input we do not control.
     *
     * @param  Builder<SearchDocument>  $query
     * @return Collection<int, SearchDocument>
     */
    private function searchPostgres(Builder $query, string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return $query
            ->where(function (Builder $q) use ($term, $like): void {
                $q->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$term])
                    // Second arm: catches partial words that full-text search misses
                    // because it only matches whole lexemes.
                    ->orWhere('title', 'ILIKE', $like)
                    ->orWhere('body', 'ILIKE', $like);
            })
            // Rank descending, then our own type weighting, then newest — so an exact
            // decision beats a transcript mention, and recent beats old.
            ->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('english', ?)) DESC", [$term])
            ->orderBy('weight')
            ->orderByDesc('meeting_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Fallback for drivers without full-text search (SQLite in tests).
     *
     * @param  Builder<SearchDocument>  $query
     * @return Collection<int, SearchDocument>
     */
    private function searchFallback(Builder $query, string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return $query
            ->where(function (Builder $q) use ($like): void {
                $q->where('title', 'LIKE', $like)
                    ->orWhere('body', 'LIKE', $like);
            })
            ->orderBy('weight')
            ->orderByDesc('meeting_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Trim and length-check a term.
     *
     * @return string|null Null when the term is too short to be worth querying.
     */
    private function normalise(string $term): ?string
    {
        $term = trim(preg_replace('/\s+/', ' ', $term) ?? '');

        return mb_strlen($term) >= self::MIN_TERM_LENGTH ? $term : null;
    }

    /**
     * Escape LIKE wildcards in user input.
     *
     * Without this, a term containing `%` matches everything and `_` matches any
     * character — not a security hole (the value is still bound), but a search for
     * "100%" would return the entire workspace.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
