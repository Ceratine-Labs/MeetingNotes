<?php

namespace Modules\Tenancy\Services;

use Modules\Tenancy\Contracts\SeatLimitProvider;
use Modules\Tenancy\Models\Organisation;

/**
 * Answers "may this organisation add another member?" and phrases the answer
 * for a customer when it cannot.
 *
 * Thin on purpose. The *limit* comes from whatever SeatLimitProvider is bound
 * (Billing in production, UnlimitedSeats otherwise); this class only holds the
 * counting rule and the wording, so both the invite form's validation and the
 * members page's UI state read from one place and cannot disagree.
 */
class SeatGuard
{
    public function __construct(private readonly SeatLimitProvider $limits) {}

    /**
     * Is there room for one more member?
     *
     * Pending invitations deliberately do NOT count against the limit. Counting
     * them would mean an unaccepted invite from three weeks ago silently blocks
     * a real hire, and the invited person may never accept at all. The seat is
     * consumed on acceptance, where addMember runs.
     */
    public function hasRoomFor(Organisation $organisation): bool
    {
        $limit = $this->limits->seatLimitFor($organisation);

        if ($limit === null) {
            return true;
        }

        return $organisation->seatsInUse() < $limit;
    }

    /**
     * Seats remaining, or null when unlimited.
     */
    public function remainingFor(Organisation $organisation): ?int
    {
        $limit = $this->limits->seatLimitFor($organisation);

        if ($limit === null) {
            return null;
        }

        // Clamped at zero: an organisation that shrank its plan below its
        // current member count would otherwise report a negative remainder,
        // which reads as nonsense in the UI.
        return max(0, $limit - $organisation->seatsInUse());
    }

    /**
     * The message shown when the limit blocks an invite.
     *
     * Names the plan and the number, because "seat limit reached" without
     * either forces the customer to go hunting for what their limit even is.
     */
    public function limitMessageFor(Organisation $organisation): string
    {
        $limit = $this->limits->seatLimitFor($organisation);
        $plan = $this->limits->planNameFor($organisation);

        if ($limit === null) {
            return 'This workspace has no seat limit.';
        }

        $seats = $limit === 1 ? '1 seat' : "{$limit} seats";

        return $plan !== null
            ? "Your {$plan} plan includes {$seats} and they are all in use. Upgrade your plan to invite more people."
            : "This workspace is limited to {$seats} and they are all in use.";
    }
}
