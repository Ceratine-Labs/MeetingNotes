<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Admin\Notifications\AdminPasswordReset;
use Modules\Core\Traits\HasUuid;

/**
 * A back-office (staff) account — Ryan and his partner.
 *
 * Deliberately NOT a customer `User`, and not related to one. See the
 * v1__01_admin_tables migration for the full reasoning; the short version is that
 * a compromise of the customer-facing auth path must not be able to produce a
 * back-office session.
 *
 * Named AdminUser rather than Admin so that `Modules\Admin\Models\Admin` — a class
 * whose name matches its own namespace segment — never has to be read twice.
 *
 * There are no roles here. With two staff members, a role hierarchy would be
 * ceremony without benefit; if a third person ever needs restricted access, that is
 * the moment to add one, not now.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property string|null $theme
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $last_login_ip
 */
class AdminUser extends Authenticatable
{
    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'admins';

    /**
     * Attribute defaults for a newly instantiated account.
     *
     * `is_active` is mirrored here even though the column already defaults to true,
     * because a database default is only applied on INSERT — it does not populate the
     * in-memory model. Without this, `AdminUser::create(...)` returns an instance whose
     * `is_active` is **null**, and `canAuthenticate()` reads that as "not active".
     *
     * That is a security-relevant boolean, so it must never be null anywhere: relying
     * on a re-read from the database to make an authorisation check come out right is
     * the kind of assumption that holds until the one code path that does not re-read.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * `is_active` is absent on purpose: revoking access is a deliberate action
     * through a dedicated method, never something a mass-assigned request array
     * can flip.
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
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * May this account sign in right now?
     *
     * Checked at login in addition to the credential check, because Laravel's guard
     * has no concept of a deactivated account — a correct password on a deactivated
     * row would otherwise succeed.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && ! $this->trashed();
    }

    /**
     * Send a reset link through the admin-specific notification and broker.
     *
     * Overriding the framework default matters: the inherited implementation builds
     * a URL for the customer `password.reset` route, which would send staff to the
     * customer reset form — where the token, issued against the admin broker, would
     * not validate.
     *
     * @param  string  $token  Plaintext reset token. Documented rather than declared:
     *         the parent (CanResetPassword) declares it untyped, and PHP forbids
     *         narrowing a parameter type when overriding a non-constructor method.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new AdminPasswordReset($token, $this->email));
    }

    /**
     * Record a successful sign-in.
     *
     * Targeted update so it does not bump updated_at — which should mean "this
     * account's details were changed", not "this person logged in".
     */
    public function recordLogin(?string $ip): void
    {
        $this->newQuery()->whereKey($this->getKey())->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }
}
