<?php

namespace Modules\Billing\Support;

use Illuminate\Support\Carbon;

/**
 * A snapshot of an organisation's generation allowance right now.
 *
 * One object answers every question the UI and the enforcement path ask — how
 * many are left, should we warn, when does it reset — so the usage meter in the
 * sidebar, the billing page and the pre-generation check cannot disagree with
 * each other. They all read this.
 *
 * Immutable: it is a measurement taken at a moment, not mutable state.
 */
readonly class QuotaStatus
{
    /**
     * @param  int  $used  Credits consumed in the current period.
     * @param  int|null  $limit  Credits allowed. **null means unlimited**, and 0
     *                           means none at all — the two must never be
     *                           conflated by a falsy check.
     * @param  Carbon  $periodStart  Start of the metering window.
     * @param  Carbon  $periodEnd  When the allowance resets.
     * @param  string  $planName  For display, e.g. "Free", "Team".
     * @param  bool  $subscriptionUsable  False when the subscription has lapsed
     *                                    beyond its grace period. Generation is
     *                                    blocked regardless of remaining credits.
     */
    public function __construct(
        public int $used,
        public ?int $limit,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $planName,
        public bool $subscriptionUsable = true,
    ) {}

    /**
     * Is the allowance unlimited?
     */
    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    /**
     * Credits left, or null when unlimited.
     *
     * Clamped at zero: an organisation downgraded to a smaller plan mid-period
     * can have used more than its new limit, and a negative number reads as
     * nonsense in the UI.
     */
    public function remaining(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * May another generation run?
     *
     * Both conditions matter: an active free plan with credits left passes, and so
     * does an unlimited plan — but a lapsed subscription fails even with credits
     * showing, because the entitlement itself has ended.
     */
    public function allowsGeneration(): bool
    {
        if (! $this->subscriptionUsable) {
            return false;
        }

        return $this->limit === null || $this->used < $this->limit;
    }

    /**
     * Percentage of the allowance consumed, 0–100, for the progress bar.
     *
     * Unlimited reports 0 rather than null so the view needs no special case; the
     * meter is hidden for unlimited plans anyway.
     */
    public function percentUsed(): int
    {
        if ($this->limit === null || $this->limit === 0) {
            return 0;
        }

        return (int) min(100, round(($this->used / $this->limit) * 100));
    }

    /**
     * Should the UI warn that the allowance is nearly gone?
     *
     * Threshold is config('billing.quota_warning_threshold'). Deliberately false
     * once exhausted — at that point the customer gets the hard upgrade prompt,
     * and showing an amber "running low" warning next to it would be noise.
     */
    public function shouldWarn(): bool
    {
        $remaining = $this->remaining();

        if ($remaining === null || $remaining === 0 || $this->limit === 0) {
            return false;
        }

        return ($remaining / $this->limit) <= config('billing.quota_warning_threshold');
    }

    /**
     * "3 of 30 used" — the one-line summary shown in the sidebar meter.
     */
    public function summary(): string
    {
        if ($this->limit === null) {
            return "{$this->used} generated this period";
        }

        return "{$this->used} of {$this->limit} used";
    }
}
