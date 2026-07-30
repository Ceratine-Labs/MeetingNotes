<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Core\Models\BaseModel;

/**
 * A user's membership of an organisation, and the role they hold in it.
 *
 * Modelled as a first-class Eloquent model rather than a plain pivot table
 * because it carries behaviour (role checks), an audit trail (who invited whom,
 * when they joined) and a soft-delete history of removed members. A bare
 * `belongsToMany` pivot would give us none of that.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $user_id
 * @property string $role
 * @property string|null $invited_by_user_id
 * @property \Illuminate\Support\Carbon|null $joined_at
 */
class Membership extends BaseModel
{
    /**
     * Billing and destruction. Exactly one per organisation — the person who
     * created it, or whoever ownership was transferred to.
     */
    public const ROLE_OWNER = 'owner';

    /**
     * Manages members and organisation settings. No access to billing, so an
     * office manager can run the workspace without seeing the card details.
     */
    public const ROLE_ADMIN = 'admin';

    /**
     * Creates and edits minutes. The default for an invited colleague.
     */
    public const ROLE_MEMBER = 'member';

    /**
     * Roles ordered most to least privileged. Order matters — atLeast() walks
     * this list, so inserting a new role in the wrong position silently changes
     * every permission check.
     *
     * @var list<string>
     */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    /**
     * Roles that may be handed out when inviting someone.
     *
     * Owner is excluded on purpose: you cannot invite a second owner, because
     * ownership is transferred (a deliberate, separate action with its own
     * confirmation), not granted.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = [self::ROLE_ADMIN, self::ROLE_MEMBER];

    protected $table = 'organisation_user';

    protected $fillable = [
        'organisation_id',
        'user_id',
        'role',
        'invited_by_user_id',
        'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Does this membership hold at least the given role?
     *
     * Roles are a strict hierarchy, so an owner passes an admin check and an
     * admin passes a member check. Comparing positions in self::ROLES means
     * permission checks read as intent ("at least admin") instead of as a list
     * of role names that has to be updated everywhere a role is added.
     *
     * @param  string  $role  One of the self::ROLE_* constants.
     */
    public function atLeast(string $role): bool
    {
        $holds = array_search($this->role, self::ROLES, true);
        $needs = array_search($role, self::ROLES, true);

        // An unrecognised role on either side fails closed. A typo in a
        // permission check must deny access, never grant it.
        if ($holds === false || $needs === false) {
            return false;
        }

        return $holds <= $needs;
    }

    /**
     * Owner — may manage billing and delete the organisation.
     */
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * Owner or admin — may manage members and organisation settings.
     */
    public function canManageOrganisation(): bool
    {
        return $this->atLeast(self::ROLE_ADMIN);
    }

    /**
     * Owner only — may change plans, cards and cancel the subscription.
     *
     * Separate from canManageOrganisation() because "runs the workspace" and
     * "controls the money" are genuinely different jobs at a customer.
     */
    public function canManageBilling(): bool
    {
        return $this->isOwner();
    }

    /**
     * Human label for the role, for tables and dropdowns.
     */
    public function roleLabel(): string
    {
        return ucfirst($this->role);
    }
}
