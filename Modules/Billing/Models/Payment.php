<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Tenancy\Concerns\BelongsToOrganisation;
use Modules\Tenancy\Models\Organisation;

/**
 * One payment attempt.
 *
 * A row is written **before** the customer is sent to Paystack, in `pending`
 * state, and updated once the outcome is known. Recording the intent first is
 * what makes an abandoned checkout visible: without it, a customer who reaches
 * the payment page and closes the tab leaves no trace, and "they say they paid
 * but we have nothing" becomes unanswerable.
 *
 * `provider_payload` keeps the verified Paystack response whole. When a charge is
 * disputed months later, the raw payload is the evidence.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $subscription_id
 * @property string $reference
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property string|null $paystack_reference
 * @property string|null $channel
 * @property string|null $card_last4
 * @property string|null $card_brand
 * @property array<string, mixed>|null $provider_payload
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class Payment extends BaseModel
{
    use BelongsToOrganisation;

    /**
     * Created, customer sent to Paystack, outcome not yet known.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Verified successful — server-side, against Paystack's API. Never set from
     * the browser callback alone.
     */
    public const STATUS_SUCCESS = 'success';

    /**
     * Paystack reported a failure (declined card, insufficient funds).
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Customer never completed the checkout.
     */
    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'payments';

    protected $fillable = [
        'organisation_id',
        'subscription_id',
        'reference',
        'amount_cents',
        'currency',
        'status',
        'paystack_reference',
        'channel',
        'card_last4',
        'card_brand',
        'provider_payload',
        'failure_reason',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'provider_payload' => 'array',
            'paid_at' => 'datetime',
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
     * Generate a payment reference.
     *
     * Shape: MN-<base36 timestamp>-<8 random hex>. Three requirements it meets:
     *
     *   - **Unique** without a database round trip (the random half).
     *   - **Recognisable** in Paystack's dashboard as ours (the MN- prefix),
     *     which matters when reconciling by hand.
     *   - **Not sequential.** A guessable reference would let someone probe
     *     other customers' transactions in any endpoint that accepts one.
     */
    public static function generateReference(): string
    {
        return sprintf('MN-%s-%s', base_convert((string) now()->timestamp, 10, 36), bin2hex(random_bytes(4)));
    }

    /**
     * Amount as a decimal string for display, e.g. "149.00".
     *
     * Display only — arithmetic stays in integer cents.
     */
    public function formattedAmount(): string
    {
        return number_format($this->amount_cents / 100, 2);
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
