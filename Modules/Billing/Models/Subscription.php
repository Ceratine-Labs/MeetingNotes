<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Models\BaseModel;
use Modules\Tenancy\Concerns\BelongsToOrganisation;
use Modules\Tenancy\Models\Organisation;

/**
 * An organisation's subscription to a plan.
 *
 * The entitlement columns here (price_cents, generation_quota, seat_limit,
 * features) are a **snapshot taken at subscribe time**, not a live read of the
 * plan. Everything that asks "what is this customer allowed to do?" must read
 * this row, never `$subscription->plan`. Reading through the relation would mean
 * an admin editing the Team plan silently changes what every existing Team
 * customer gets mid-period — a billing correctness bug, and the kind that only
 * shows up in an angry support email.
 *
 * Exactly one subscription per organisation is live at a time. Superseded ones
 * are kept (status cancelled/expired) as the billing history.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $plan_id
 * @property string $status
 * @property string $plan_code
 * @property string $plan_name
 * @property int $price_cents
 * @property string $currency
 * @property int|null $generation_quota
 * @property int|null $seat_limit
 * @property array<string, mixed>|null $features
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property string|null $paystack_subscription_code
 * @property string|null $paystack_customer_code
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $past_due_since
 */
class Subscription extends BaseModel
{
    use BelongsToOrganisation;

    /**
     * Paid up (or free) and fully usable.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * A renewal charge failed. Access continues through the grace period, then
     * the organisation is downgraded to free — never locked out of its own data.
     */
    public const STATUS_PAST_DUE = 'past_due';

    /**
     * Customer cancelled. Still usable until current_period_end, because they
     * have paid for that time.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Fully wound down — superseded by another subscription, or a cancellation
     * whose paid period has elapsed.
     */
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'subscriptions';

    protected $fillable = [
        'organisation_id',
        'plan_id',
        'status',
        'plan_code',
        'plan_name',
        'price_cents',
        'currency',
        'generation_quota',
        'seat_limit',
        'features',
        'current_period_start',
        'current_period_end',
        'paystack_subscription_code',
        'paystack_customer_code',
        'paystack_email_token',
        'cancelled_at',
        'past_due_since',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'generation_quota' => 'integer',
            'seat_limit' => 'integer',
            'features' => 'array',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'past_due_since' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    /**
     * The plan this was created from.
     *
     * For display and admin reporting only. Never read entitlements through
     * here — see the class docblock.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'subscription_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(GenerationUsage::class, 'subscription_id');
    }

    /**
     * Subscriptions that still confer access.
     *
     * Cancelled is included on purpose: the customer paid for the current period
     * and keeps it until current_period_end.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_ACTIVE,
            self::STATUS_PAST_DUE,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Does this subscription currently grant access to the product?
     *
     * Cancelled-but-not-yet-elapsed counts. Past due counts while inside the
     * grace period — cutting a customer off the instant a card expires is how
     * you turn a payment retry into a churned account.
     */
    public function isUsable(): bool
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => true,
            self::STATUS_CANCELLED => $this->current_period_end->isFuture(),
            self::STATUS_PAST_DUE => $this->withinGracePeriod(),
            default => false,
        };
    }

    /**
     * Still inside the post-failure grace window?
     *
     * Length comes from config('billing.grace_period_days') so it can be tuned
     * without a deploy of new logic.
     */
    public function withinGracePeriod(): bool
    {
        if ($this->past_due_since === null) {
            return false;
        }

        return $this->past_due_since
            ->copy()
            ->addDays(config('billing.grace_period_days'))
            ->isFuture();
    }

    /**
     * Has the customer asked to cancel?
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isFree(): bool
    {
        return $this->plan_code === Plan::CODE_FREE;
    }

    /**
     * Is the generation allowance unlimited on this subscription?
     *
     * null = unlimited, 0 = none. Never collapse the two with a falsy check.
     */
    public function hasUnlimitedGenerations(): bool
    {
        return $this->generation_quota === null;
    }

    public function hasUnlimitedSeats(): bool
    {
        return $this->seat_limit === null;
    }

    /**
     * Has the current metering window elapsed?
     *
     * True means the quota is due to roll over; QuotaService does the rolling,
     * lazily, on the next generation attempt.
     */
    public function periodHasElapsed(): bool
    {
        return $this->current_period_end->isPast();
    }

    /**
     * Read a feature flag from the snapshot.
     *
     * @param  string  $key  e.g. 'custom_prompts', 'api'
     */
    public function feature(string $key, mixed $default = false): mixed
    {
        return data_get($this->features, $key, $default);
    }

    /**
     * Human status for the billing screen.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_PAST_DUE => 'Payment failed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_EXPIRED => 'Expired',
            default => ucfirst($this->status),
        };
    }
}
