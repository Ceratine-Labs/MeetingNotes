<?php

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Services\OrganisationContext;
use Modules\Tenancy\Services\OrganisationService;
use Modules\Tenancy\Services\SeatGuard;

/**
 * The workspace members screen: who is in, what role they hold, and the
 * outstanding invitations.
 *
 * Every route here sits behind `organisation.role:admin`.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly OrganisationContext $context,
        private readonly OrganisationService $organisations,
        private readonly SeatGuard $seats,
    ) {}

    /**
     * List members and pending invitations.
     */
    public function index(): View
    {
        $organisation = $this->context->getOrFail();

        return view('tenancy::members.index', [
            'organisation' => $organisation,
            // Eager-loaded: the table renders a name and email per row, and
            // without this it is one query per member.
            'memberships' => $organisation->memberships()->with('user')->get(),
            'invitations' => $organisation->invitations()->pending()->latest()->get(),
            'seatsRemaining' => $this->seats->remainingFor($organisation),
            'canInvite' => $this->seats->hasRoomFor($organisation),
            'seatLimitMessage' => $this->seats->limitMessageFor($organisation),
            'assignableRoles' => Membership::ASSIGNABLE_ROLES,
        ]);
    }

    /**
     * Change a member's role.
     *
     * The membership is looked up through the current organisation's own
     * relation rather than by primary key alone. That is what stops an admin of
     * workspace A from passing workspace B's membership id and editing a member
     * they have no business touching.
     */
    public function updateRole(Request $request, string $membership): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(Membership::ASSIGNABLE_ROLES)],
        ]);

        $organisation = $this->context->getOrFail();
        $target = $organisation->memberships()->findOrFail($membership);

        try {
            $this->organisations->changeRole($target, $validated['role']);
        } catch (\DomainException $e) {
            // Domain rules here are things the user could plausibly attempt
            // (demoting the owner), so they surface as a message rather than a
            // 500 or a silent no-op.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Role updated.');
    }

    /**
     * Remove a member from the workspace.
     */
    public function destroy(Request $request, string $membership): RedirectResponse
    {
        $organisation = $this->context->getOrFail();
        $target = $organisation->memberships()->with('user')->findOrFail($membership);

        // Removing yourself would drop you out of the workspace you are
        // standing in mid-request; "leave workspace" is a different flow with
        // its own redirect, and is not built yet.
        if ($target->user_id === $request->user()->getKey()) {
            return back()->with('error', 'You cannot remove yourself from a workspace.');
        }

        try {
            $this->organisations->removeMember($organisation, $target);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$target->user->name} was removed from this workspace.");
    }
}
