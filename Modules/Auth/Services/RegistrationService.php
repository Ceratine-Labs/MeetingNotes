<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Tenancy\Models\Invitation;
use Modules\Tenancy\Models\Organisation;
use Modules\Tenancy\Services\InvitationService;
use Modules\Tenancy\Services\OrganisationService;

/**
 * Creates a new account, and gives it somewhere to work.
 *
 * There are two shapes of registration and they must not be confused:
 *
 *   **Ordinary sign-up** — create the user, create a workspace they own,
 *   Billing puts them on the free plan (via the OrganisationCreated event).
 *
 *   **Sign-up from an invitation** — create the user and add them to the
 *   *existing* workspace they were invited to. Deliberately no new workspace:
 *   someone joining their employer's account should not also end up owning a
 *   stray empty one, which would then show up in their switcher forever.
 *
 * The whole thing is one transaction. A user row with no workspace is a broken
 * account — they can sign in, but every tenant route bounces them to "create a
 * workspace" and support has to work out what happened.
 */
class RegistrationService
{
    public function __construct(
        private readonly OrganisationService $organisations,
        private readonly InvitationService $invitations,
    ) {}

    /**
     * Register a user and place them in a workspace.
     *
     * @param  array{name: string, email: string, password: string, organisation_name?: string|null}  $data
     *         Already validated by RegisterRequest.
     * @param  string|null  $invitationToken  Plaintext token from an invite link.
     *         An unknown, expired or already-used token is ignored rather than
     *         rejected: the person still gets an account and their own
     *         workspace, and can ask for a fresh invitation.
     * @return array{user: User, organisation: Organisation, joined_via_invitation: bool}
     */
    public function register(array $data, ?string $invitationToken = null): array
    {
        // Resolved before the transaction: it is a read, and doing it here keeps
        // the decision about which branch we are in out of the write path.
        $invitation = $invitationToken !== null
            ? $this->invitations->findByToken($invitationToken)
            : null;

        [$user, $organisation] = DB::transaction(function () use ($data, $invitation): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                // Hashed by the model's `password` => 'hashed' cast.
                'password' => $data['password'],
            ]);

            if ($invitation !== null) {
                $this->invitations->accept($invitation, $user);

                return [$user, $invitation->organisation];
            }

            $organisation = $this->organisations->create(
                $this->workspaceNameFor($data),
                $user
            );

            return [$user, $organisation];
        });

        // Fired after commit. Laravel's SendEmailVerificationNotification
        // listener hangs off this; queueing a mail job inside the transaction
        // risks the worker reading a user row that has not been committed yet.
        event(new Registered($user));

        return [
            'user' => $user,
            'organisation' => $organisation,
            'joined_via_invitation' => $invitation !== null,
        ];
    }

    /**
     * The workspace name to use when the form left it blank.
     *
     * Most people signing up alone do not want to think about naming a
     * "workspace" — they want to paste a transcript. Defaulting to their own
     * name gets them through the form, and it is renameable in workspace
     * settings afterwards.
     *
     * @param  array{name: string, organisation_name?: string|null}  $data
     */
    private function workspaceNameFor(array $data): string
    {
        $provided = trim((string) ($data['organisation_name'] ?? ''));

        if ($provided !== '') {
            return $provided;
        }

        // First name only — "Ryan's workspace" reads better than
        // "Ryan Cruickshank's workspace" in a navbar with limited room.
        $firstName = explode(' ', trim($data['name']))[0];

        return "{$firstName}'s workspace";
    }

    /**
     * Look up a pending invitation for pre-filling the registration form.
     *
     * Returns null for anything not currently acceptable, so the form simply
     * renders as an ordinary sign-up rather than showing a broken invite state.
     */
    public function pendingInvitation(?string $token): ?Invitation
    {
        if ($token === null) {
            return null;
        }

        return $this->invitations->findByToken($token);
    }
}
