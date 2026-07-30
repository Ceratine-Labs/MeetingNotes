<?php

namespace Modules\Tenancy\Services;

use Modules\Tenancy\Contracts\SeatLimitProvider;
use Modules\Tenancy\Models\Organisation;

/**
 * Default SeatLimitProvider: no seat limit at all.
 *
 * Bound by TenancyServiceProvider and overridden by Billing when that module is
 * present. It is what runs in unit tests, in a deployment with billing turned
 * off, and if the Billing module is ever removed.
 *
 * Failing open (unlimited) rather than closed (zero seats) is deliberate: the
 * worst case here is a customer briefly adding a member they were not entitled
 * to, which billing reconciliation catches. Failing closed would block a paying
 * customer from adding staff because of a problem on our side.
 */
class UnlimitedSeats implements SeatLimitProvider
{
    public function seatLimitFor(Organisation $organisation): ?int
    {
        return null;
    }

    public function planNameFor(Organisation $organisation): ?string
    {
        return null;
    }
}
