<?php

namespace Modules\Tenancy\Contracts;

use Modules\Tenancy\Models\Organisation;

/**
 * Tells Tenancy how many members an organisation is entitled to.
 *
 * This interface exists to keep the dependency pointing the right way. Seat
 * limits are a *commercial* rule owned by the Billing module, but the code that
 * needs to enforce them (inviting a member) lives in Tenancy — and Tenancy
 * boots first (priority 5 vs 25) and must work with Billing absent entirely.
 *
 * So Tenancy declares what it needs, Billing binds an implementation that reads
 * the organisation's plan, and the default binding
 * (UnlimitedSeats) treats every organisation as unrestricted. That default is
 * the safe direction to fail: a billing outage should not stop a paying
 * customer from adding a colleague.
 */
interface SeatLimitProvider
{
    /**
     * How many members this organisation may have.
     *
     * @return int|null Null means unlimited. Callers must handle null rather
     *                  than treating it as zero.
     */
    public function seatLimitFor(Organisation $organisation): ?int;

    /**
     * Name of the plan the limit came from, for use in the message shown to
     * the customer ("Your Starter plan includes 3 seats").
     *
     * @return string|null Null when there is no plan to name.
     */
    public function planNameFor(Organisation $organisation): ?string;
}
