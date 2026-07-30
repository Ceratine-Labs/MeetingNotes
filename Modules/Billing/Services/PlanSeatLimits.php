<?php

namespace Modules\Billing\Services;

use Modules\Tenancy\Contracts\SeatLimitProvider;
use Modules\Tenancy\Models\Organisation;

/**
 * Billing's answer to Tenancy's seat-limit question.
 *
 * Bound over Tenancy's UnlimitedSeats default by BillingServiceProvider. The
 * indirection is what keeps the dependency pointing the right way: Tenancy boots
 * first (priority 5 vs 25) and must work with Billing absent, so it declares the
 * interface and Billing supplies the plan-aware implementation.
 *
 * Limits come from the **subscription snapshot**, never from the plan. An admin
 * raising the Team plan's seat count must not silently change what existing Team
 * customers are entitled to mid-period — see the Subscription class docblock.
 */
class PlanSeatLimits implements SeatLimitProvider
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Seats this organisation may fill, or null for unlimited.
     *
     * An organisation with no live subscription also gets null (unlimited) rather
     * than zero. Failing open is the right direction here: the realistic cause is
     * a data gap on our side, and blocking a customer from adding staff over it
     * would be a worse outcome than a seat we later reconcile.
     */
    public function seatLimitFor(Organisation $organisation): ?int
    {
        return $this->subscriptions->currentFor($organisation)?->seat_limit;
    }

    /**
     * Plan name for the "your Starter plan includes 3 seats" message.
     */
    public function planNameFor(Organisation $organisation): ?string
    {
        return $this->subscriptions->currentFor($organisation)?->plan_name;
    }
}
