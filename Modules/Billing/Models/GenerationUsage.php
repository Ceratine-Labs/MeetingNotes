<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Tenancy\Concerns\BelongsToOrganisation;
use Modules\Tenancy\Models\Organisation;

/**
 * One consumed generation credit — the metering ledger.
 *
 * Append-only by intent: a row is written when a generation **succeeds**, and
 * usage is always derived by summing rows in a period rather than by keeping a
 * running counter on the subscription. Two reasons that matters:
 *
 *   - A counter can drift. Two concurrent generations that both read-then-write
 *     a counter lose one increment; summing an append-only ledger cannot.
 *   - "Why does it say I used 14?" is answerable. Each row names the meeting, the
 *     user, the model and the tokens, so a disputed number can be walked
 *     line by line.
 *
 * A failed generation writes nothing — customers must not be charged a credit
 * for our error or a provider timeout.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $subscription_id
 * @property string|null $meeting_id
 * @property string|null $user_id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $credits
 * @property string|null $model_used
 * @property int|null $tokens_used
 */
class GenerationUsage extends BaseModel
{
    use BelongsToOrganisation;

    protected $table = 'generation_usages';

    protected $fillable = [
        'organisation_id',
        'subscription_id',
        'meeting_id',
        'user_id',
        'period_start',
        'period_end',
        'credits',
        'model_used',
        'tokens_used',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'credits' => 'integer',
            'tokens_used' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * Usage recorded within a subscription's current billing window.
     *
     * Filtered on `period_start` (which is stamped from the subscription when
     * the credit is consumed) rather than on created_at, so the count stays
     * correct even if the subscription's window is later adjusted.
     */
    public function scopeForPeriod(Builder $query, Subscription $subscription): Builder
    {
        return $query->where('period_start', $subscription->current_period_start);
    }
}
