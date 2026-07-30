<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\HasUuid;

/**
 * A received provider webhook, recorded before it is acted on.
 *
 * Paystack retries deliveries, so duplicate events are normal operation rather
 * than an error case. The unique index on (provider, event_id) makes idempotency
 * a database guarantee: a second delivery of the same event fails the insert,
 * and the handler skips it. That is deliberately stronger than a
 * "have I seen this?" query, which has a race between the check and the write.
 *
 * Extends Model directly rather than BaseModel: this is an append-only audit log
 * with no soft deletes (a webhook that arrived, arrived) and it is not
 * organisation-scoped, because the payload has to be parsed before we know which
 * organisation it concerns.
 *
 * @property string $id
 * @property string $provider
 * @property string|null $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $error
 * @property int $attempts
 */
class WebhookEvent extends Model
{
    use HasUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'billing_webhook_events';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'processed_at',
        'error',
        'attempts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Has this event already been handled successfully?
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Mark handled.
     *
     * Clears any previous error, so a retry that succeeds does not leave a stale
     * failure message on the row that would read as an unresolved problem.
     */
    public function markProcessed(): void
    {
        $this->update([
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * Record a handling failure without consuming the event.
     *
     * `processed_at` stays null so Paystack's next retry (or a manual replay
     * from the admin UI) picks it up again. The attempt counter is what surfaces
     * an event stuck in a retry loop.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'error' => mb_substr($error, 0, 2000),
            'attempts' => $this->attempts + 1,
        ]);
    }
}
