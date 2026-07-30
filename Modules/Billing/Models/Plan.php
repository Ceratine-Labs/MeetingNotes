<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;

/**
 * A subscription tier.
 *
 * Plans are data, not code: the admin back office edits prices, quotas and
 * feature flags, and the public pricing page renders from these rows. That is
 * why nothing in the codebase hardcodes a price or a quota — it reads the plan.
 *
 * Editing a plan does NOT change existing customers. Each subscription carries
 * its own snapshot of the price and entitlements agreed at signup (see the
 * subscriptions table). So a price rise applies to new subscribers and renewals,
 * never retroactively to somebody's current period.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $tagline
 * @property int $price_cents
 * @property string $currency
 * @property string $interval
 * @property int|null $generation_quota
 * @property int|null $seat_limit
 * @property array<string, mixed>|null $features
 * @property string|null $paystack_plan_code
 * @property bool $is_public
 * @property bool $is_active
 * @property int $sort
 */
class Plan extends BaseModel
{
    /**
     * The permanent free tier. Every new organisation starts here, and any
     * organisation whose payments lapse returns here rather than losing access
     * to its data.
     *
     * Referenced by code in several places, so it is a constant rather than a
     * string literal scattered about.
     */
    public const CODE_FREE = 'free';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_ANNUALLY = 'annually';

    /**
     * Used by the free plan, which never renews and is never sent to Paystack.
     */
    public const INTERVAL_NONE = 'none';

    protected $table = 'plans';

    protected $fillable = [
        'code',
        'name',
        'tagline',
        'price_cents',
        'currency',
        'interval',
        'generation_quota',
        'seat_limit',
        'features',
        'paystack_plan_code',
        'is_public',
        'is_active',
        'sort',
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
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    /**
     * Plans a customer may subscribe to, in display order.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort');
    }

    /**
     * Plans shown on the public pricing page.
     *
     * `is_public` exists separately from `is_active` so a bespoke or grandfathered
     * plan can stay subscribable (existing customers keep renewing) without
     * appearing in the price list.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort');
    }

    /**
     * The permanent free tier.
     *
     * @throws \RuntimeException When it is missing. Registration provisions
     *         every new organisation onto this plan, so its absence breaks
     *         sign-up entirely — better to fail with an explanation than to
     *         create subscriptions pointing at null.
     */
    public static function free(): self
    {
        $plan = static::query()->where('code', self::CODE_FREE)->first();

        if ($plan === null) {
            throw new \RuntimeException(
                'No plan with code "free" exists. Run `php artisan seed:master` — '
                .'the free plan is required for registration to work.'
            );
        }

        return $plan;
    }

    /**
     * Is this the free tier?
     */
    public function isFree(): bool
    {
        return $this->code === self::CODE_FREE;
    }

    /**
     * Does this plan require a payment to subscribe to?
     */
    public function isPaid(): bool
    {
        return $this->price_cents > 0;
    }

    /**
     * Can this plan be billed recurringly through Paystack?
     *
     * Both halves are needed: a paid plan with no `paystack_plan_code` has not
     * been pushed to Paystack yet and cannot be subscribed to.
     */
    public function isBillable(): bool
    {
        return $this->isPaid() && ! empty($this->paystack_plan_code);
    }

    /**
     * Is the generation allowance unlimited?
     *
     * Explicit helper because `null` (unlimited) and `0` (none) are opposites,
     * and a bare falsy check would treat them the same — handing unlimited
     * generations to a plan meant to have none, or vice versa.
     */
    public function hasUnlimitedGenerations(): bool
    {
        return $this->generation_quota === null;
    }

    /**
     * Is the seat allowance unlimited?
     */
    public function hasUnlimitedSeats(): bool
    {
        return $this->seat_limit === null;
    }

    /**
     * Price as a decimal string for display, e.g. "149.00".
     *
     * Formatting only — never feed this back into arithmetic. Amounts stay in
     * integer cents everywhere they are calculated with.
     */
    public function formattedPrice(): string
    {
        return number_format($this->price_cents / 100, 2);
    }

    /**
     * Read a feature flag.
     *
     * @param  string  $key  e.g. 'custom_prompts', 'api', 'exports'
     * @param  mixed  $default  Returned when the plan does not mention the key.
     */
    public function feature(string $key, mixed $default = false): mixed
    {
        return data_get($this->features, $key, $default);
    }
}
