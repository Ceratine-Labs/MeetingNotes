<?php

namespace Modules\Search\Console;

use Illuminate\Console\Command;
use Modules\Minutes\Models\Meeting;
use Modules\Search\Models\SearchDocument;
use Modules\Search\Services\SearchIndexer;

/**
 * Rebuilds the search index from the meetings themselves.
 *
 * Needed in three situations:
 *
 *   - **Backfill.** The index is maintained going forward by the generation pipeline,
 *     so meetings that existed before the Search module did have no rows at all.
 *   - **After changing the indexer.** Adding a field to what gets indexed does nothing
 *     for existing rows until they are rebuilt.
 *   - **Repair.** The index is derived data — throwing it away and rebuilding is always
 *     safe, and is the first thing to try if search looks wrong.
 *
 * Chunked rather than loaded whole: transcripts are long, and a workspace with a few
 * thousand meetings would otherwise exhaust memory.
 */
class ReindexSearchCommand extends Command
{
    protected $signature = 'search:reindex
                            {--organisation= : Limit to one organisation UUID}
                            {--fresh : Delete the whole index first instead of rebuilding per meeting}';

    protected $description = 'Rebuild the workspace search index from existing meetings';

    public function __construct(private readonly SearchIndexer $indexer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('fresh')) {
            // Truncate rather than per-meeting delete: much faster, and it also clears
            // orphaned rows whose meeting was hard-deleted.
            $deleted = SearchDocument::withoutOrganisationScope()->count();
            SearchDocument::withoutOrganisationScope()->delete();
            $this->warn("Cleared {$deleted} existing index rows.");
        }

        // withoutOrganisationScope with an explicit filter: this is a console command
        // spanning every tenant on purpose, which is exactly what the escape hatch is
        // for.
        $query = Meeting::withoutOrganisationScope()
            ->whereNotNull('organisation_id')
            ->with(['transcript', 'decisions', 'actionItems'])
            ->when(
                $this->option('organisation'),
                fn ($q) => $q->where('organisation_id', $this->option('organisation'))
            );

        $total = $query->count();

        if ($total === 0) {
            // The likeliest cause on a converted install, so say it rather than leaving
            // a bare "0 meetings".
            $this->info('No meetings with a workspace to index. Run `php artisan saas:backfill` first if this is a converted install.');

            return self::SUCCESS;
        }

        $this->info("Indexing {$total} meeting(s)…");
        $bar = $this->output->createProgressBar($total);
        $documents = 0;

        $query->chunkById(50, function ($meetings) use (&$documents, $bar): void {
            foreach ($meetings as $meeting) {
                $documents += $this->indexer->index($meeting);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Wrote {$documents} search document(s) across {$total} meeting(s).");

        return self::SUCCESS;
    }
}
