<?php

namespace Modules\Auth\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Core\Traits\HasUuid;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Models\Organisation;

/**
 * An end user of the product — someone who signs in at /login and works inside
 * one or more organisations.
 *
 * Two things this model is NOT:
 *
 *   - **It is not the SaaS back-office account.** Ryan and his partner sign in
 *     at /admin/login against the separate `admins` table and `admin` guard.
 *     Keeping the tables apart means a compromised customer account can never
 *     be escalated into back-office access, and the two password-reset flows
 *     cannot be confused for one another.
 *
 *   - **It is not organisation-scoped.** A user exists independently of any
 *     workspace and may belong to several. Their permissions are per-workspace
 *     and live on the membership row (organisation_user.role), not here.
 *
 * There is deliberately no role column here at all. The legacy `users.role` admin
 * flag was dropped in v1__02_drop_users_role.php: a dormant privilege column that
 * nothing checks is a standing hazard, because the next mass-assignment mistake
 * would be granting a privilege something might start honouring later.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $theme
 * @property string|null $current_organisation_id
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $last_login_ip
 */
class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasUuid;

    /**
     * Supplies hasVerifiedEmail() / sendEmailVerificationNotification().
     *
     * Verification is NOT required to sign in — it gates the first *generation*
     * only (the expensive, abusable action), so a new customer can look around
     * the product before going to find our email. See the `verified` middleware
     * on the Minutes generation route.
     */
    use MustVerifyEmail;

    use Notifiable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'users';

    /**
     * `theme` and `current_organisation_id` are absent on purpose: both are UI
     * state written by targeted updates in ThemeService and
     * OrganisationResolver, never mass-assigned from a request.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * This user's memberships across all workspaces.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Workspaces this user belongs to.
     */
    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class, 'organisation_user', 'user_id', 'organisation_id')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Memberships where this user is the owner.
     *
     * Used for the abuse cap on how many workspaces one person may create
     * (config tenancy.max_organisations_per_user).
     */
    public function ownedMemberships(): HasMany
    {
        return $this->memberships()->where('role', Membership::ROLE_OWNER);
    }

    /**
     * Record a successful sign-in.
     *
     * A targeted update rather than save(): this fires on every login and must
     * not bump updated_at (which would make "profile last changed" meaningless)
     * or collide with anything else in flight on the row.
     *
     * @param  string|null  $ip  Request IP; null when unavailable (console login).
     */
    public function recordLogin(?string $ip): void
    {
        $this->newQuery()->whereKey($this->getKey())->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }
}
