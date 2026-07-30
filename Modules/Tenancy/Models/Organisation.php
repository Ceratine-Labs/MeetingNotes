<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Core\Models\BaseModel;

/**
 * An organisation — the workspace that owns minutes and holds a subscription.
 *
 * This is the tenant boundary. Everything a customer creates hangs off exactly
 * one organisation, and the OrganisationScope on tenant-owned models makes that
 * boundary automatic rather than something each query has to remember.
 *
 * Note the organisation itself is NOT organisation-scoped (it would filter
 * itself out of its own lookups). Access control for reading an organisation is
 * membership: see Organisation::membershipFor() and the EnsureOrganisation
 * middleware, which only ever binds an organisation the user belongs to.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_user_id
 * @property string $timezone
 * @property array<string, mixed>|null $settings
 */
class Organisation extends BaseModel
{
    protected $table = 'organisations';

    protected $fillable = [
        'name',
        'slug',
        'owner_user_id',
        'timezone',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Membership rows, including the role each user holds here.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'organisation_id');
    }

    /**
     * Users who belong to this organisation.
     *
     * The pivot carries `role`, so callers can read
     * `$organisation->users->first()->pivot->role` without a second query.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organisation_user', 'organisation_id', 'user_id')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Outstanding and historical invitations.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'organisation_id');
    }

    /**
     * This user's membership row, or null if they do not belong here.
     *
     * The single place to answer "may this user act in this organisation?".
     * Middleware and policies both go through it so there is one definition of
     * membership rather than several subtly different queries.
     */
    public function membershipFor(User $user): ?Membership
    {
        return $this->memberships()
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * How many members currently count against the plan's seat limit.
     *
     * Soft-deleted memberships are excluded by BaseModel's SoftDeletes, so a
     * removed member frees their seat immediately — which is the behaviour a
     * customer expects after clicking "remove".
     */
    public function seatsInUse(): int
    {
        return $this->memberships()->count();
    }

    /**
     * Build a URL-safe, unique slug from a name.
     *
     * Collisions are resolved with a numeric suffix rather than a random
     * string, because the slug is customer-visible and "acme-2" reads better
     * than "acme-x7f3". The uniqueness check ignores soft-deleted rows on
     * purpose: the column has a unique index that a soft-deleted row still
     * occupies, so we must not hand back a slug the database will reject.
     */
    public static function generateSlug(string $name): string
    {
        $base = str($name)->slug()->limit(48, '')->toString();

        // A name of only punctuation or non-Latin characters can slug to
        // nothing; fall back so we never write an empty unique column.
        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
