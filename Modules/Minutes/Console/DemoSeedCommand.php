<?php

namespace Modules\Minutes\Console;

use Illuminate\Console\Command;
use Modules\Auth\Models\User;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Core\Services\SettingsService;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Minutes\Support\DemoMinutes;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Models\Organisation;

/**
 * Seed a browsable demo workspace: a verified user, an organisation, and
 * meetings in every state a customer can encounter (ready with full
 * minutes, processing, failed), including decisions and action items in
 * open and done states.
 *
 * This is environment tooling, NOT part of the seed master: the seed
 * master's registry is for one-time schema-adjacent seeds on every
 * install, while this command exists so a developer, demo box or the E2E
 * suite can conjure realistic data on demand. It refuses to run in
 * production for the same reason the FakeDriver does.
 *
 * Idempotent by wipe-and-recreate: rerunning drops the demo workspace's
 * meetings and reseeds them, so the state is always exactly this file.
 */
class DemoSeedCommand extends Command
{
    public const EMAIL = 'demo@notefiend.test';

    public const PASSWORD = 'demo-password-123';

    protected $signature = 'demo:seed
        {--fake-llm : Also switch the LLM provider setting to the fake driver}';

    protected $description = 'Seed a demo workspace with meetings, minutes and action items (never in production)';

    public function handle(MinutesGenerator $generator, SettingsService $settings): int
    {
        if (app()->isProduction()) {
            $this->error('demo:seed does not run in production.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Demo User',
                // Hashed by the model's password cast.
                'password' => self::PASSWORD,
            ]
        );
        $user->forceFill(['email_verified_at' => now()])->save();

        $organisation = Organisation::query()->firstOrCreate(
            ['slug' => 'demo-workspace'],
            [
                'name' => 'Demo Workspace',
                'owner_user_id' => $user->id,
                'timezone' => 'Africa/Johannesburg',
            ]
        );

        Membership::query()->firstOrCreate(
            ['organisation_id' => $organisation->id, 'user_id' => $user->id],
            ['role' => Membership::ROLE_OWNER, 'joined_at' => now()]
        );

        // Cheap-path pointer for OrganisationResolver, matching what real
        // registration sets.
        $user->forceFill(['current_organisation_id' => $organisation->id])->save();

        /*
         * An active subscription on a hidden unlimited plan: without one the
         * quota meter reads "0 of 0" and generation is refused, which would
         * defeat the point of a demo workspace. Unlimited (NULL quota) so
         * repeated E2E runs against a reused database can never drain it.
         */
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'demo-unlimited'],
            [
                'name' => 'Demo Unlimited',
                'price_cents' => 0,
                'currency' => 'ZAR',
                'interval' => Plan::INTERVAL_MONTHLY,
                'generation_quota' => null,
                'seat_limit' => null,
                'features' => ['exports' => ['md', 'docx', 'pdf'], 'custom_prompts' => true, 'api' => true],
                'is_public' => false,
                'is_active' => true,
                'sort' => 999,
            ]
        );

        Subscription::query()->firstOrCreate(
            ['organisation_id' => $organisation->id, 'plan_id' => $plan->id],
            [
                'status' => Subscription::STATUS_ACTIVE,
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'generation_quota' => $plan->generation_quota,
                'seat_limit' => $plan->seat_limit,
                'features' => $plan->features,
                'current_period_start' => now()->subDay(),
                'current_period_end' => now()->addYear(),
            ]
        );

        // Wipe-and-recreate the workspace's meetings so reruns converge on
        // this file's state instead of accumulating duplicates.
        Meeting::withoutOrganisationScope()
            ->where('organisation_id', $organisation->id)
            ->get()
            ->each(fn (Meeting $meeting) => $meeting->forceDelete());

        foreach (DemoMinutes::meetings() as $demo) {
            $meeting = Meeting::query()->create([
                'organisation_id' => $organisation->id,
                'user_id' => $user->id,
                'title' => $demo['title'],
                'meeting_date' => $demo['date'],
                'source_type' => 'paste',
                'status' => $demo['status'],
                'progress_stage' => $demo['progress_stage'] ?? null,
                'error' => $demo['error'] ?? null,
            ]);

            $meeting->transcript()->create(['raw_text' => $demo['transcript']]);

            if ($demo['status'] === Meeting::STATUS_READY) {
                // The same persist() path production uses: sections JSON,
                // rendered HTML, child rows, search index. Demo minutes are
                // therefore structurally indistinguishable from real ones.
                $generator->persist($meeting, $demo['sections']);
            }

            foreach ($demo['done_refs'] ?? [] as $ref) {
                $meeting->actionItems()->where('ref', $ref)->first()?->markDone($user->id);
            }
        }

        if ($this->option('fake-llm')) {
            $settings->set('llm.provider', 'fake', 'llm');
            $this->info('LLM provider setting switched to the fake driver.');
        }

        $this->info('Demo workspace seeded.');
        $this->line('  Login: ' . self::EMAIL . ' / ' . self::PASSWORD);

        return self::SUCCESS;
    }
}
