<?php

namespace Modules\Tenancy\Services;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Tenancy\Events\OrganisationCreated;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Models\Organisation;

/**
 * Creating, updating and staffing organisations.
 *
 * All the business rules for a workspace live here rather than in controllers
 * (house convention), which also means the registration flow, an invite
 * acceptance and an admin back-office action all create members the same way
 * and cannot drift.
 */
class OrganisationService
{
    /**
     * Create an organisation with the given user as its owner.
     *
     * Wrapped in a transaction because a half-created workspace is worse than
     * no workspace: an organisation with no owner membership is invisible to
     * its creator (nothing can bind it as their current organisation) and can
     * only be cleaned up by hand in the database.
     *
     * The OrganisationCreated event is dispatched **after** the transaction
     * commits, not inside it. Listeners provision billing and may queue jobs;
     * a job that starts before commit can read a row that does not exist yet,
     * and a listener that throws inside the transaction would roll back a
     * workspace the user has already been told about.
     *
     * @param  string  $name  Customer-supplied workspace name.
     * @param  User  $owner  Becomes the owner member.
     * @param  string|null  $timezone  Defaults to the column default (SAST).
     */
    public function create(string $name, User $owner, ?string $timezone = null): Organisation
    {
        $organisation = DB::transaction(function () use ($name, $owner, $timezone): Organisation {
            $organisation = Organisation::query()->create([
                'name' => $name,
                'slug' => Organisation::generateSlug($name),
                'owner_user_id' => $owner->getKey(),
                'timezone' => $timezone ?: config('tenancy.default_timezone'),
            ]);

            Membership::query()->create([
                'organisation_id' => $organisation->getKey(),
                'user_id' => $owner->getKey(),
                'role' => Membership::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            return $organisation;
        });

        OrganisationCreated::dispatch($organisation);

        return $organisation;
    }

    /**
     * Update the organisation's own details.
     *
     * The slug is regenerated only when the name actually changes, so an edit
     * that only touches the timezone does not silently move the workspace's
     * URL — links people have already shared keep working.
     *
     * @param  array{name?: string, timezone?: string, settings?: array<string, mixed>}  $attributes
     */
    public function update(Organisation $organisation, array $attributes): Organisation
    {
        if (isset($attributes['name']) && $attributes['name'] !== $organisation->name) {
            $attributes['slug'] = Organisation::generateSlug($attributes['name']);
        }

        $organisation->fill($attributes)->save();

        return $organisation;
    }

    /**
     * Add an existing user to an organisation, or restore/return their
     * membership if they were here before.
     *
     * Idempotent by design. Invite acceptance can be retried (double-clicked
     * link, refreshed page) and must not create a duplicate membership row or
     * blow up with a constraint error in the user's face.
     *
     * @param  string  $role  One of Membership::ASSIGNABLE_ROLES.
     * @param  User|null  $invitedBy  Recorded for the audit trail.
     */
    public function addMember(
        Organisation $organisation,
        User $user,
        string $role = Membership::ROLE_MEMBER,
        ?User $invitedBy = null
    ): Membership {
        // withTrashed: a previously removed member is re-activated rather than
        // duplicated, which keeps their original joined_at history intact.
        $existing = Membership::withTrashed()
            ->where('organisation_id', $organisation->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            // Re-inviting at a higher role should promote; never demote an
            // owner back down to member by re-running an old invite.
            if (! $existing->atLeast($role)) {
                $existing->role = $role;
            }

            $existing->joined_at ??= now();
            $existing->save();

            return $existing;
        }

        return Membership::query()->create([
            'organisation_id' => $organisation->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'invited_by_user_id' => $invitedBy?->getKey(),
            'joined_at' => now(),
        ]);
    }

    /**
     * Remove a member from an organisation.
     *
     * Soft delete, so the audit trail survives and re-inviting them restores
     * the original row (see addMember).
     *
     * @throws \DomainException When asked to remove the owner — an organisation
     *         without an owner has nobody who can pay for it or delete it, so
     *         ownership must be transferred first.
     */
    public function removeMember(Organisation $organisation, Membership $membership): void
    {
        if ($membership->isOwner()) {
            throw new \DomainException(
                'The owner cannot be removed from the organisation. Transfer '
                .'ownership to another member first.'
            );
        }

        DB::transaction(function () use ($organisation, $membership): void {
            $membership->delete();

            // If this was the removed user's current workspace, clear the
            // pointer — otherwise their next request tries to bind an
            // organisation they no longer belong to and hits the middleware's
            // access check as a confusing 403 rather than a clean redirect.
            User::query()
                ->where('id', $membership->user_id)
                ->where('current_organisation_id', $organisation->getKey())
                ->update(['current_organisation_id' => null]);
        });
    }

    /**
     * Change an existing member's role.
     *
     * @param  string  $role  One of Membership::ASSIGNABLE_ROLES.
     *
     * @throws \DomainException When targeting the owner. Demoting the owner
     *         through this path would leave the organisation ownerless;
     *         transferOwnership() is the operation that safely swaps the pair.
     */
    public function changeRole(Membership $membership, string $role): Membership
    {
        if ($membership->isOwner()) {
            throw new \DomainException(
                "The owner's role cannot be changed directly. Transfer ownership instead."
            );
        }

        if (! in_array($role, Membership::ASSIGNABLE_ROLES, true)) {
            throw new \DomainException("[{$role}] is not a role that can be assigned.");
        }

        $membership->role = $role;
        $membership->save();

        return $membership;
    }

    /**
     * Hand ownership of an organisation to another member.
     *
     * Both sides move inside one transaction: the new owner is promoted, the
     * old owner steps down to admin, and the organisation's denormalised
     * owner_user_id is repointed. Doing this in three separate writes risks a
     * window with two owners (or none), which breaks every canManageBilling()
     * check that assumes exactly one.
     *
     * @throws \DomainException When the target is not a member of this
     *         organisation.
     */
    public function transferOwnership(Organisation $organisation, User $newOwner): void
    {
        $target = $organisation->membershipFor($newOwner);

        if ($target === null) {
            throw new \DomainException(
                'Ownership can only be transferred to an existing member of the organisation.'
            );
        }

        DB::transaction(function () use ($organisation, $target): void {
            $organisation->memberships()
                ->where('role', Membership::ROLE_OWNER)
                ->update(['role' => Membership::ROLE_ADMIN]);

            $target->role = Membership::ROLE_OWNER;
            $target->save();

            $organisation->owner_user_id = $target->user_id;
            $organisation->save();
        });
    }
}
