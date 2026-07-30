<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\GatewayException;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Tenancy\Models\Organisation;

/**
 * The subscription lifecycle: provisioning, upgrades, renewals, failure and
 * cancellation.
 *
 * One invariant runs through everything here: **an organisation has exactly one
 * live subscription at a time.** Every method that creates one supersedes the
 * previous in the same transaction, because two live subscriptions would make
 * "what is this customer entitled to?" ambiguous — and the quota check would
 * pick whichever the database happened to return first.
 *
 * Entitlements are snapshotted onto the subscription row at creation time. See
 * the Subscription class docblock for why that matters.
 */
class SubscriptionService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * The organisation's current subscription, if it has one.
     *
     * Ordered newest-first so that if data repair ever leaves two live rows, the
     * most recent wins — a defensive tiebreak, not a licence to create two.
     */
    public function currentFor(Organisation $organisation): ?Subscription
    {
        return Subscription::withoutOrganisationScope()
            ->where('organisation_id', $organisation->getKey())
            ->live()
            ->latest('created_at')
            ->first();
    }

    /**
     * Put an organisation on the free plan.
     *
     * Called in two situations, which is why it is idempotent:
     *   - a brand-new organisation (via the OrganisationCreated event);
     *   - an organisation being downgraded after cancellation or a failed
     *     renewal.
     *
     * Returning the existing subscription when one is already live means a
     * duplicate OrganisationCreated event, or a retried listener, cannot create a
     * second free subscription alongside a paid one — which would silently
     * downgrade a paying customer.
     */
    public function provisionFree(Organisation $organisation): Subscription
    {
        $existing = $this->currentFor($organisation);

        if ($existing !== null && $existing->isFree() && $existing->status === Subscription::STATUS_ACTIVE) {
            return $existing;
        }

        return $this->start($organisation, Plan::free());
    }

    /**
     * Start a subscription to a plan, superseding whatever came before.
     *
     * @param  array{
     *     paystack_subscription_code?: string|null,
     *     paystack_customer_code?: string|null,
     *     paystack_email_token?: string|null,
     * }  $providerRefs  Identifiers from a completed checkout. Empty for the free
     *                   plan, which never goes near Paystack.
     */
    public function start(Organisation $organisation, Plan $plan, array $providerRefs = []): Subscription
    {
        return DB::transaction(function () use ($organisation, $plan, $providerRefs): Subscription {
            $this->expireLiveSubscriptions($organisation);

            $periodStart = now();

            return Subscription::query()->create([
                'organisation_id' => $organisation->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => Subscription::STATUS_ACTIVE,

                // The snapshot. Everything that asks what this customer may do
                // reads these columns, never $subscription->plan.
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'generation_quota' => $plan->generation_quota,
                'seat_limit' => $plan->seat_limit,
                'features' => $plan->features,

                'current_period_start' => $periodStart,
                'current_period_end' => $this->periodEnd($periodStart, $plan->interval),

                'paystack_subscription_code' => $providerRefs['paystack_subscription_code'] ?? null,
                'paystack_customer_code' => $providerRefs['paystack_customer_code'] ?? null,
                'paystack_email_token' => $providerRefs['paystack_email_token'] ?? null,
            ]);
        });
    }

    /**
     * Roll the metering window forward.
     *
     * Called lazily by QuotaService when it notices the period has elapsed,
     * rather than by a scheduled job. That is a deliberate choice: a cron that
     * fails to run would leave every customer's quota frozen at last month's
     * usage, locking out paying customers. Doing it on read means the worst case
     * is that nobody notices, because nothing was blocked.
     *
     * The new window starts at the old one's end, not at `now()`, so periods stay
     * contiguous and a customer who does not log in for two months does not get a
     * shifted billing anniversary.
     */
    public function rollPeriod(Subscription $subscription): Subscription
    {
        $start = $subscription->current_period_end->copy();

        // Catch up if several periods elapsed while the account sat idle.
        // Without the loop, one roll would leave the window still in the past and
        // the quota would appear exhausted.
        $end = $this->periodEnd($start, $this->intervalFor($subscription));

        while ($end->isPast()) {
            $start = $end->copy();
            $end = $this->periodEnd($start, $this->intervalFor($subscription));
        }

        $subscription->update([
            'current_period_start' => $start,
            'current_period_end' => $end,
        ]);

        return $subscription;
    }

    /**
     * Record a successful renewal charge: back to active, grace period cleared.
     */
    public function recordRenewal(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'past_due_since' => null,
        ]);

        return $this->rollPeriod($subscription);
    }

    /**
     * Record a failed renewal charge.
     *
     * Starts the grace period rather than cutting access immediately — a card
     * that expired over a weekend should not read as churn. `past_due_since` is
     * only stamped the first time, so repeated failures do not keep extending the
     * grace window indefinitely.
     */
    public function markPastDue(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => Subscription::STATUS_PAST_DUE,
            'past_due_since' => $subscription->past_due_since ?? now(),
        ]);

        return $subscription;
    }

    /**
     * Cancel at the customer's request.
     *
     * Access continues to `current_period_end` — they paid for that time. The
     * downgrade to free happens when the period elapses (see
     * downgradeIfElapsed), not now.
     *
     * A Paystack failure here is logged and swallowed **on purpose**: the customer
     * clicked cancel and must see it take effect. Leaving them "still subscribed"
     * because our API call failed is the worse outcome; the orphaned Paystack
     * subscription is reconciled from the admin payments screen.
     */
    public function cancel(Subscription $subscription): Subscription
    {
        if ($subscription->paystack_subscription_code !== null && $subscription->paystack_email_token !== null) {
            try {
                $this->gateway->disableSubscription(
                    $subscription->paystack_subscription_code,
                    $subscription->paystack_email_token
                );
            } catch (GatewayException $e) {
                Log::error('Could not disable Paystack subscription during cancellation', [
                    'subscription_id' => $subscription->getKey(),
                    'paystack_subscription_code' => $subscription->paystack_subscription_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $subscription;
    }

    /**
     * Drop an organisation to free when its paid access has genuinely run out.
     *
     * Returns the free subscription if a downgrade happened, null if the
     * subscription is still usable. Called from the quota check, so an expired
     * subscription is caught the next time the customer tries to do anything —
     * again, no cron in the critical path.
     *
     * Nothing is ever deleted. The customer keeps every meeting and every set of
     * minutes; they simply return to free-tier limits.
     */
    public function downgradeIfElapsed(Subscription $subscription): ?Subscription
    {
        if ($subscription->isUsable()) {
            return null;
        }

        Log::info('Downgrading organisation to the free plan', [
            'organisation_id' => $subscription->organisation_id,
            'from_plan' => $subscription->plan_code,
            'reason' => $subscription->status,
        ]);

        return $this->start($subscription->organisation, Plan::free());
    }

    /**
     * Mark every live subscription for an organisation as expired.
     *
     * Uses withoutOrganisationScope deliberately: this runs from webhook handlers
     * and console jobs where no organisation is bound to the context, and the
     * organisation_id filter here is explicit, so the tenancy scope would add
     * nothing but a thrown exception.
     */
    private function expireLiveSubscriptions(Organisation $organisation): void
    {
        Subscription::withoutOrganisationScope()
            ->where('organisation_id', $organisation->getKey())
            ->live()
            ->update(['status' => Subscription::STATUS_EXPIRED]);
    }

    /**
     * When a period that starts at $start should end.
     *
     * The free plan has interval 'none' but still needs a metering window — its
     * three generations a month have to reset somehow — so it is treated as
     * monthly. Anything unrecognised also falls back to monthly, which is the
     * conservative direction: a shorter window resets the allowance more often
     * rather than granting a year of it by accident.
     */
    private function periodEnd(Carbon $start, string $interval): Carbon
    {
        return match ($interval) {
            Plan::INTERVAL_ANNUALLY => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };
    }

    /**
     * The billing interval for an existing subscription.
     *
     * Read from the related plan, falling back to monthly. The interval is the one
     * entitlement NOT snapshotted onto the subscription row — worth knowing,
     * because it means a plan whose interval is edited does affect existing
     * subscribers' renewal cadence. That is intentional (the cadence is what
     * Paystack bills on, and Paystack is the source of truth there), unlike price
     * and quota which must stay fixed.
     */
    private function intervalFor(Subscription $subscription): string
    {
        return $subscription->plan?->interval ?? Plan::INTERVAL_MONTHLY;
    }
}
