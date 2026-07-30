<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Services\OrganisationService;

/**
 * One-shot (but safely repeatable) migration of pre-SaaS data into workspaces.
 *
 * Before the SaaS conversion this application was single-tenant: users owned
 * meetings directly and no `organisations` table existed. This command gives
 * every legacy user a workspace and attaches their meetings to it.
 *
 * Why a command and not a migration:
 *
 *   - Creating an organisation must go through OrganisationService so the
 *     OrganisationCreated event fires and Billing provisions the free
 *     subscription. A migration cannot depend on the plans table being seeded —
 *     migrations run before seeders.
 *   - A data-repair step needs to be re-runnable. Migrations run once.
 *
 * Idempotent throughout: users who already have a membership are skipped, and
 * only meetings with a NULL organisation_id are touched. Running it twice is a
 * no-op, which is what makes it safe to include in a deploy script.
 *
 * Until it has run, legacy meetings are **invisible** rather than exposed — the
 * organisation scope matches on an exact id, so NULL matches no workspace. See
 * the v1__03_meetings_organisation migration.
 */
class BackfillOrganisationsCommand extends Command
{
    protected $signature = 'saas:backfill
                            {--dry-run : Report what would change without writing anything}';

    protected $description = 'Give pre-SaaS users a workspace and attach their existing meetings to it';

    public function __construct(private readonly OrganisationService $organisations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        $usersBackfilled = $this->backfillUsers($dryRun);
        $meetingsBackfilled = $this->backfillMeetings($dryRun);

        $this->newLine();
        $this->info(sprintf(
            '%s %d workspace(s) and attached %d meeting(s).',
            $dryRun ? 'Would create' : 'Created',
            $usersBackfilled,
            $meetingsBackfilled
        ));

        if (! $dryRun && $meetingsBackfilled > 0) {
            $this->line('Legacy meetings are now visible inside their owner\'s workspace.');
        }

        $orphaned = $this->orphanedMeetingCount();

        if ($orphaned > 0) {
            // Meetings whose owning user no longer exists (soft-deleted or
            // removed). Reported rather than guessed at: silently parking someone
            // else's minutes in an arbitrary workspace would be a data leak.
            $this->newLine();
            $this->warn(sprintf(
                '%d meeting(s) could not be attached — their owning user no longer exists. '
                .'They remain invisible to every workspace. Assign or delete them deliberately.',
                $orphaned
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Give every user without a workspace one, with themselves as owner.
     *
     * @return int Number of workspaces created (or that would be).
     */
    private function backfillUsers(bool $dryRun): int
    {
        // withTrashed on users would resurrect deleted accounts into billable
        // workspaces, so only live users are considered.
        $users = User::query()
            ->whereNotIn('id', Membership::query()->select('user_id'))
            ->get();

        if ($users->isEmpty()) {
            $this->line('No users need a workspace.');

            return 0;
        }

        $created = 0;

        foreach ($users as $user) {
            $firstName = explode(' ', trim($user->name))[0];
            $name = "{$firstName}'s workspace";

            $this->line("  {$user->email} → \"{$name}\"");

            if (! $dryRun) {
                // Through the service, so OrganisationCreated fires and Billing
                // provisions the free subscription.
                $this->organisations->create($name, $user);
            }

            $created++;
        }

        return $created;
    }

    /**
     * Attach meetings with no organisation to their owner's workspace.
     *
     * A single UPDATE ... FROM rather than a per-row loop: this may be a large
     * table, and the correlated subquery version would issue one query per
     * meeting.
     *
     * The owner's workspace is the one where they hold the owner role, taking the
     * oldest if somehow there are several — the same "longest-standing wins" rule
     * OrganisationResolver uses, so a user's backfilled meetings and their default
     * workspace agree.
     *
     * @return int Number of meetings attached (or that would be).
     */
    private function backfillMeetings(bool $dryRun): int
    {
        $pending = DB::table('meetings')->whereNull('organisation_id')->count();

        if ($pending === 0) {
            $this->line('No meetings need attaching.');

            return 0;
        }

        $this->line("  {$pending} meeting(s) with no workspace");

        if ($dryRun) {
            return $pending;
        }

        // Raw SQL on purpose: the Meeting model carries the organisation scope,
        // which would filter out exactly the NULL rows this needs to update.
        $affected = DB::update(<<<'SQL'
            UPDATE meetings
            SET organisation_id = owned.organisation_id
            FROM (
                SELECT DISTINCT ON (user_id) user_id, organisation_id
                FROM organisation_user
                WHERE role = 'owner' AND deleted_at IS NULL
                ORDER BY user_id, created_at
            ) AS owned
            WHERE meetings.organisation_id IS NULL
              AND meetings.user_id = owned.user_id
        SQL);

        return $affected;
    }

    /**
     * Meetings that still have no workspace after the backfill.
     */
    private function orphanedMeetingCount(): int
    {
        return DB::table('meetings')->whereNull('organisation_id')->count();
    }
}
