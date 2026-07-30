<?php

namespace Modules\Tenancy\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Auth\Models\User;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Models\Organisation;

/**
 * Works out which organisation a signed-in user is acting in, and switches it.
 *
 * Kept separate from OrganisationContext on purpose: the context is a dumb
 * holder that the scope reads on every query, while this class holds the
 * *policy* for choosing what goes in it. Splitting them means the resolution
 * rules can be tested without a request, and the hot path (the scope) stays
 * trivial.
 *
 * Resolution order:
 *   1. `current_organisation_id` on the user's row — survives logout, new
 *      browsers and new devices, which the session alone would not.
 *   2. Their oldest membership — the workspace they have had longest is the
 *      least surprising default for someone whose pointer is null (a brand-new
 *      user, or someone whose current workspace was just deleted).
 *
 * Whatever comes back, membership is re-verified. The pointer is a *hint*, not
 * an authorisation: a user removed from a workspace still has its id on their
 * row until something clears it.
 */
class OrganisationResolver
{
    public function __construct(private readonly OrganisationContext $context) {}

    /**
     * Resolve and bind the user's current organisation.
     *
     * @return Organisation|null Null when the user belongs to no organisation
     *         at all — the caller (EnsureOrganisation) decides what to do,
     *         which is normally to send them somewhere they can create one.
     */
    public function resolveFor(User $user): ?Organisation
    {
        $organisation = $this->fromPointer($user) ?? $this->fromOldestMembership($user);

        if ($organisation === null) {
            return null;
        }

        // Repair a stale or empty pointer so the next request takes the cheap
        // path instead of re-running the membership join every time.
        if ($user->current_organisation_id !== $organisation->getKey()) {
            $this->rememberPointer($user, $organisation);
        }

        $this->context->set($organisation);

        return $organisation;
    }

    /**
     * Switch the user into a different organisation they belong to.
     *
     * @return bool False when they are not a member — the caller should treat
     *         that as a failed switch, not an error to display, because the
     *         usual cause is a stale switcher in a tab left open after being
     *         removed from the workspace.
     */
    public function switchTo(User $user, string $organisationId): bool
    {
        $organisation = Organisation::query()->find($organisationId);

        if ($organisation === null || $organisation->membershipFor($user) === null) {
            return false;
        }

        $this->rememberPointer($user, $organisation);
        $this->context->set($organisation);

        return true;
    }

    /**
     * Every organisation this user belongs to, for the switcher dropdown.
     *
     * @return Collection<int, Organisation>
     */
    public function organisationsFor(User $user): Collection
    {
        return Organisation::query()
            ->whereIn('id', $this->membershipQuery($user)->select('organisation_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The organisation the user's row points at, if they still belong to it.
     */
    private function fromPointer(User $user): ?Organisation
    {
        if (empty($user->current_organisation_id)) {
            return null;
        }

        $organisation = Organisation::query()->find($user->current_organisation_id);

        // Membership re-check: the pointer alone must never grant access.
        if ($organisation === null || $organisation->membershipFor($user) === null) {
            return null;
        }

        return $organisation;
    }

    /**
     * Their longest-standing organisation.
     */
    private function fromOldestMembership(User $user): ?Organisation
    {
        $membership = $this->membershipQuery($user)
            ->orderBy('created_at')
            ->first();

        return $membership?->organisation;
    }

    /**
     * Live (non-removed) memberships for a user.
     */
    private function membershipQuery(User $user): Builder
    {
        return Membership::query()->where('user_id', $user->getKey());
    }

    /**
     * Persist the pointer without touching the rest of the user row.
     *
     * A targeted update rather than save(): this runs on ordinary page loads
     * (pointer repair), and it must not bump updated_at, fire observers, or
     * overwrite a concurrent profile edit just to record a UI preference.
     */
    private function rememberPointer(User $user, Organisation $organisation): void
    {
        $user->current_organisation_id = $organisation->getKey();

        $user->newQuery()
            ->whereKey($user->getKey())
            ->update(['current_organisation_id' => $organisation->getKey()]);
    }
}
