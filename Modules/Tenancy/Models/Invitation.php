<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Modules\Auth\Models\User;
use Modules\Core\Models\BaseModel;

/**
 * A pending invitation for someone to join an organisation.
 *
 * Security shape worth understanding before changing anything here: the row
 * stores only a SHA-256 **hash** of the invite token. The plaintext token
 * exists exactly once, in the email that goes out. Accepting an invite hashes
 * the token from the URL and looks the row up by that hash — so a stolen
 * database dump yields no usable invite links, for the same reason we never
 * store plaintext passwords.
 *
 * SHA-256 rather than bcrypt is the right choice here specifically because the
 * token is 32 bytes of `random_bytes` entropy, not a human-chosen secret:
 * there is nothing to brute-force, and we need a deterministic hash to look
 * the row up by. (Password hashing needs the opposite properties.)
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property string|null $invited_by_user_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property string|null $accepted_user_id
 */
class Invitation extends BaseModel
{
    /**
     * The invitation itself is the notifiable, not a User — the whole point is
     * that the recipient usually has no account yet, so there is no user row to
     * notify. routeNotificationForMail() below supplies the address.
     */
    use Notifiable;

    protected $table = 'organisation_invitations';

    protected $fillable = [
        'organisation_id',
        'email',
        'role',
        'token_hash',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
    ];

    /**
     * The hash is not a secret in the same way a password is, but it has no
     * business being serialised into an API response or a log line either.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Invitations that can still be accepted: not yet used, not yet expired.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * Has this invitation already been used?
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Has the acceptance window closed?
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Can this invitation still be accepted right now?
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * Where notifications for this invitation are delivered.
     *
     * Required by Notifiable: without it Laravel looks for an `email` attribute
     * on a User and this model would silently fail to send.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    /**
     * Hash a plaintext invite token into the form stored on the row.
     *
     * Both issuing and accepting go through this method so the two can never
     * drift apart — if they did, every invite link in the wild would break at
     * once and the cause would be extremely non-obvious.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
