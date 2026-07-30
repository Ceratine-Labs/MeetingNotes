<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Plan;

/**
 * The initial subscription tiers.
 *
 * These are **starting values, not settled pricing.** Every field here is editable
 * from the admin plan editor, and the public pricing page renders from the
 * database rather than from this file — so changing a price is an admin action,
 * not a deploy.
 *
 * Seed-master rules apply (see SeedMaster): this runs once and is then recorded in
 * seed_registry. Editing this file afterwards will NOT re-run it — the seed master
 * warns about the checksum mismatch and skips. To change live prices, use the
 * admin editor. To change the shipped defaults for future installs, add a v2
 * seeder.
 *
 * Metering model, as decided with Ryan: generations per month, plus a seat limit
 * and feature flags per tier.
 *
 * Prices are in ZAR cents. R149.00 is 14900.
 */
class PlanSeeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 10;

    public function run(): void
    {
        /*
         * Free. Permanent, no card, no expiry.
         *
         * Three generations is enough to produce real minutes from real meetings
         * and decide whether the output is good — which is the only thing that
         * sells this product — while staying far below the point where a farmed
         * account costs meaningful LLM spend.
         *
         * Markdown export is included deliberately: a customer must always be able
         * to get their own minutes out. See FeatureGate::BASELINE_EXPORTS.
         */
        $this->upsert([
            'code' => Plan::CODE_FREE,
            'name' => 'Free',
            'tagline' => 'Try it on a real meeting.',
            'price_cents' => 0,
            'interval' => Plan::INTERVAL_NONE,
            'generation_quota' => 3,
            'seat_limit' => 1,
            'features' => [
                'exports' => ['md'],
                'custom_prompts' => false,
                'api' => false,
                'support' => 'community',
            ],
            'sort' => 10,
        ]);

        /*
         * Starter. The individual professional — a consultant, a company secretary,
         * a one-person practice who minutes a few meetings a week.
         */
        $this->upsert([
            'code' => 'starter',
            'name' => 'Starter',
            'tagline' => 'For one person who minutes regularly.',
            'price_cents' => 14900,
            'interval' => Plan::INTERVAL_MONTHLY,
            'generation_quota' => 30,
            'seat_limit' => 3,
            'features' => [
                'exports' => ['md', 'docx', 'pdf'],
                'custom_prompts' => false,
                'api' => false,
                'support' => 'email',
            ],
            'sort' => 20,
        ]);

        /*
         * Team. The expected default for a business, and the one marked as
         * recommended on the pricing page.
         *
         * Custom prompts start here because a team with house minute conventions is
         * exactly who needs to edit the generation templates.
         */
        $this->upsert([
            'code' => 'team',
            'name' => 'Team',
            'tagline' => 'For a department that runs on its minutes.',
            'price_cents' => 44900,
            'interval' => Plan::INTERVAL_MONTHLY,
            'generation_quota' => 150,
            'seat_limit' => 10,
            'features' => [
                'exports' => ['md', 'docx', 'pdf'],
                'custom_prompts' => true,
                'api' => false,
                'support' => 'email',
                'recommended' => true,
            ],
            'sort' => 30,
        ]);

        /*
         * Business. Unlimited generations and seats.
         *
         * `generation_quota` and `seat_limit` are NULL, which means unlimited —
         * not zero. The two are opposites and the code never conflates them (see
         * Plan::hasUnlimitedGenerations).
         */
        $this->upsert([
            'code' => 'business',
            'name' => 'Business',
            'tagline' => 'Unlimited minutes, unlimited people.',
            'price_cents' => 129900,
            'interval' => Plan::INTERVAL_MONTHLY,
            'generation_quota' => null,
            'seat_limit' => null,
            'features' => [
                'exports' => ['md', 'docx', 'pdf'],
                'custom_prompts' => true,
                'api' => true,
                'support' => 'priority',
            ],
            'sort' => 40,
        ]);
    }

    /**
     * Create or update a plan by its code.
     *
     * updateOrCreate rather than create so that a forced re-run
     * (`seed:master --force=…`) repairs the shipped plans instead of failing on the
     * unique index. `paystack_plan_code` is deliberately never written here — it is
     * earned by pushing the plan to Paystack from the admin editor, and clobbering
     * it would silently break recurring billing for existing subscribers.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $attributes): void
    {
        $code = $attributes['code'];
        unset($attributes['code']);

        Plan::query()->updateOrCreate(
            ['code' => $code],
            $attributes + [
                'currency' => config('billing.currency', 'ZAR'),
                'is_public' => true,
                'is_active' => true,
            ]
        );
    }
}
