<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minutes v1.3 — bring meetings under organisation ownership.
 *
 * Only `meetings` gets the column. Transcripts, decisions and action items are
 * reached exclusively through their meeting, so scoping the aggregate root is
 * sufficient — and duplicating organisation_id down the tree would create rows
 * that can disagree with their parent, which is a worse failure than the extra
 * join it saves.
 *
 * **The column is nullable, and that is a deliberate safety property.** The
 * OrganisationScope filters on an exact organisation_id match, so any meeting
 * left with NULL is invisible to every workspace rather than visible to all of
 * them. Pre-existing rows are therefore hidden, not leaked, until they are
 * assigned an owner.
 *
 * Assigning that owner is a separate, explicit step:
 *
 *     php artisan saas:backfill
 *
 * It is a command rather than logic in this migration for two reasons: creating
 * organisations must go through OrganisationService so the OrganisationCreated
 * event fires and Billing provisions a free subscription (a migration cannot
 * rely on the plans table being seeded yet), and a repair step needs to be
 * re-runnable, which a migration is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->uuid('organisation_id')->nullable()->after('id');

            // The hot query: "this workspace's meetings, newest first". Composite
            // rather than two single-column indexes so the sort is served by the
            // index instead of a filesort on every library page load.
            $table->index(['organisation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['organisation_id', 'created_at']);
            $table->dropColumn('organisation_id');
        });
    }
};
