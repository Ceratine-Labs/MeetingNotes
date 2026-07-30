<?php

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Tenancy\Http\Requests\StoreInvitationRequest;
use Modules\Tenancy\Services\InvitationService;
use Modules\Tenancy\Services\OrganisationContext;
use Modules\Tenancy\Services\OrganisationResolver;

/**
 * Issuing, revoking and accepting workspace invitations.
 *
 * Note the split in route protection: `store` and `destroy` are admin actions
 * inside a workspace, while `show` and `accept` are reached from an email by
 * someone who may not be signed in and may not even have an account. Those two
 * therefore sit outside both the `organisation` and `auth` middleware — the
 * token in the URL is the credential.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly OrganisationContext $context,
        private readonly OrganisationResolver $resolver,
    ) {}

    /**
     * Send an invitation. Seat limits and duplicate members are already
     * rejected by StoreInvitationRequest.
     */
    public function store(StoreInvitationRequest $request): RedirectResponse
    {
        $invitation = $this->invitations->issue(
            $this->context->getOrFail(),
            $request->validated('email'),
            $request->validated('role'),
            $request->user()
        );

        return back()->with('status', "Invitation sent to {$invitation->email}.");
    }

    /**
     * Withdraw a pending invitation.
     *
     * Scoped through the current organisation's relation so an admin cannot
     * revoke another workspace's invitation by id.
     */
    public function destroy(string $invitation): RedirectResponse
    {
        $target = $this->context->getOrFail()->invitations()->findOrFail($invitation);

        $this->invitations->revoke($target);

        return back()->with('status', "Invitation to {$target->email} was withdrawn.");
    }

    /**
     * Landing page for an emailed invite link.
     *
     * Three outcomes:
     *   - Invalid/expired/used token -> a single generic "no longer valid"
     *     page. Deliberately not specific: distinguishing the cases would let
     *     anyone holding a token probe whether an invitation ever existed.
     *   - Signed in -> show what they are about to join, with an accept button.
     *   - Not signed in -> the same page, but pointing at register/login with
     *     the token carried through so they land back here afterwards.
     */
    public function show(Request $request, string $token): View
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null) {
            return view('tenancy::invitations.invalid');
        }

        return view('tenancy::invitations.show', [
            'invitation' => $invitation,
            'organisation' => $invitation->organisation,
            'token' => $token,
            // Drives the copy: an invite addressed to someone else's email is
            // still acceptable by whoever is signed in, but we say so plainly
            // rather than letting it look like a mistake.
            'emailMismatch' => $request->user() !== null
                && $request->user()->email !== $invitation->email,
        ]);
    }

    /**
     * Accept an invitation as the signed-in user, and switch into the workspace.
     *
     * Requires `auth`: the token proves the invitation is genuine, not who is
     * holding it. An unauthenticated POST here is sent to register/login first,
     * with the token preserved in the intended URL.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null) {
            return redirect()
                ->route('core.dashboard')
                ->with('error', 'That invitation link is no longer valid.');
        }

        $this->invitations->accept($invitation, $request->user());
        $this->resolver->switchTo($request->user(), $invitation->organisation_id);

        return redirect()
            ->route('core.dashboard')
            ->with('status', "You have joined {$invitation->organisation->name}.");
    }
}
