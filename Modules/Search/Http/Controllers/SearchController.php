<?php

namespace Modules\Search\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Search\Models\SearchDocument;
use Modules\Search\Services\SearchService;

/**
 * Workspace search: the navbar type-ahead and the full results page.
 *
 * Both routes sit behind `auth` + `organisation`, so results are confined to the
 * current workspace by the SearchDocument model's organisation scope. There is no
 * organisation filter in this controller — and no way to omit one.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    /**
     * JSON for the navbar dropdown.
     *
     * Kept deliberately small and grouped by meeting: this is hit on a debounce as the
     * user types, so it has to stay cheap and the payload has to be scannable rather
     * than exhaustive.
     */
    public function quick(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        $results = $this->search->quick($term)->map(fn (array $row): array => [
            'title' => $row['title'],
            'url' => $row['url'],
            'date' => $row['date'],
            'label' => $row['best']->label,
            'snippet' => $row['snippet'],
            'hits' => $row['hits'],
            'types' => $row['types'],
        ]);

        return response()->json([
            'term' => $term,
            'results' => $results,
            // The client uses this to decide between "no matches" and "keep typing".
            'too_short' => mb_strlen(trim($term)) < SearchService::MIN_TERM_LENGTH,
            'all_url' => route('search.index', ['q' => $term]),
        ]);
    }

    /**
     * The full results page, with an optional type filter.
     */
    public function index(Request $request): View
    {
        $term = (string) $request->query('q', '');
        $type = $request->query('type');

        $validTypes = [
            SearchDocument::TYPE_DECISION,
            SearchDocument::TYPE_ACTION_ITEM,
            SearchDocument::TYPE_SECTION,
            SearchDocument::TYPE_TRANSCRIPT,
        ];

        // Reject an unknown type rather than passing it through — an unrecognised value
        // would silently return nothing and look like "no results".
        $type = in_array($type, $validTypes, true) ? $type : null;

        return view('search::index', [
            'term' => $term,
            'type' => $type,
            'results' => $this->search->search($term, 50, $type),
            'types' => [
                null => 'Everything',
                SearchDocument::TYPE_DECISION => 'Decisions',
                SearchDocument::TYPE_ACTION_ITEM => 'Action items',
                SearchDocument::TYPE_SECTION => 'Minutes',
                SearchDocument::TYPE_TRANSCRIPT => 'Transcripts',
            ],
            'minLength' => SearchService::MIN_TERM_LENGTH,
        ]);
    }
}
