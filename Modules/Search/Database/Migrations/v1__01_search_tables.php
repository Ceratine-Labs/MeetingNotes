<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search v1 — one flat, denormalised index for everything a user can search.
 *
 * **Why an index table rather than querying the source tables.**
 *
 * A useful search has to cover transcripts (long text), minutes sections (nested
 * JSON), decisions and action items (separate tables) in one ranked result set.
 * Doing that live means a UNION across four differently-shaped tables plus JSON
 * extraction, on every keystroke-triggered request. Denormalising into one row per
 * searchable thing turns that into a single indexed query.
 *
 * The cost is that the index must be maintained — see SearchIndexer, which rebuilds a
 * meeting's rows whenever its minutes are persisted, and `php artisan search:reindex`
 * for backfilling.
 *
 * **PostgreSQL full-text search, with a portable fallback.**
 *
 * On PostgreSQL this adds a generated `tsvector` column with a GIN index, which gives
 * real stemming and ranking ("budgets" matches "budget") rather than substring
 * matching. Generated rather than trigger-maintained so it cannot drift from `body`.
 *
 * SQLite (the test database) has no tsvector, so the column and index are skipped
 * there and SearchService falls back to LIKE. The fallback is genuinely worse — no
 * stemming, no ranking — which is a deliberate trade: tests exercise the query path
 * and the wiring, production gets the good engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant boundary. Search must never cross workspaces.
            $table->uuid('organisation_id')->index();

            // What this row points at: 'transcript' | 'minutes_section' | 'decision'
            // | 'action_item'. See SearchDocument::TYPE_*.
            $table->string('type', 32)->index();

            // The meeting everything ultimately hangs off — the result link target.
            $table->uuid('meeting_id')->index();

            // The specific source row, where there is one (a decision, an action item).
            // Null for transcripts and sections, which are addressed by the meeting.
            $table->uuid('source_id')->nullable();

            // Shown as the result heading, e.g. "Q3 Budget Review — Action A2".
            $table->string('title');

            // A short label for the result row ("Decision D1", "Transcript").
            $table->string('label', 120)->nullable();

            // The searchable text. Kept whole because the snippet shown under a result
            // is extracted from it around the match — storing only a pre-made snippet
            // would mean the excerpt never reflects what the user actually searched.
            $table->text('body');

            // Result ordering within an equal relevance score. Lower first, so a
            // decision outranks a raw transcript chunk when both match equally.
            $table->unsignedSmallInteger('weight')->default(50);

            // Denormalised from the meeting so results can be sorted newest-first and
            // dated without a join.
            $table->timestamp('meeting_date')->nullable();

            $table->timestamps();

            // The hot query: this workspace's documents, best match first.
            $table->index(['organisation_id', 'type']);
            $table->index(['organisation_id', 'meeting_id']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Generated column: PostgreSQL keeps it in step with title/body automatically,
        // so it cannot go stale the way a trigger or an application-maintained column
        // can. Title is weighted 'A' and body 'B' so a term in the heading ranks above
        // the same term buried in a transcript.
        DB::statement(<<<'SQL'
            ALTER TABLE search_documents
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(body, '')), 'B')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX search_documents_vector_idx ON search_documents USING GIN (search_vector)');

        // Trigram index on title for partial-word matching ("Mar" finding "Maria"),
        // which full-text search alone will not do — it matches whole lexemes.
        // Guarded: pg_trgm may not be installable without superuser on managed hosting,
        // and its absence degrades prefix search rather than breaking anything.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX search_documents_title_trgm_idx ON search_documents USING GIN (title gin_trgm_ops)');
            DB::statement('CREATE INDEX search_documents_body_trgm_idx ON search_documents USING GIN (body gin_trgm_ops)');
        } catch (\Throwable $e) {
            // Logged rather than thrown: a missing extension must not fail a deploy.
            report($e);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
