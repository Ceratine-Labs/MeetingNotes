<?php

namespace Modules\Tenancy\Services;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Tenancy\Models\Invitation;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Models\Organisation;
use Modules\Tenancy\Notifications\OrganisationInvitation;

/**
 * Issuing, accepting and revoking organisation invitations.
 *
 * The token lifecycle is the part to understand:
 *
 *   issue()  generates 32 bytes of entropy, stores only its SHA-256 hash, and
 *            hands the plaintext to the notification. After this method
 *            returns, the plaintext exists only in the email.
 *   accept() hashes the token from the URL and looks the row up by hash.
 *
 * Nothing ever reverses a hash, and no code path logs or persists the
 * plaintext. See Invitation's class docblock for why SHA-256 is correct here
 * where bcrypt would be wrong.
 */
class InvitationService
{
    public function __construct(private readonly OrganisationService $organisations) {}

    /**
     * Invite someone to an organisation by email.
     *
     * Re-inviting an address with an outstanding invitation replaces it rather
     * than stacking a second one — otherwise a customer who clicks "invite"
     * twice leaves two live tokens, and revoking the visible one would not
     * actually close the door.
     *
     * @param  string  $email  Untrusted; normalised to lowercase here so the
     *                         acceptance lookup is case-insensitive.
     * @param  string  $role  One of Membership::ASSIGNABLE_ROLES.
     */
    public function issue(
        Organisation $organisation,
        string $email,
        string $role = Membership::ROLE_MEMBER,
        ?User $invitedBy = null
    ): Invitation {
        $email = mb_strtolower(trim($email));

        // 32 bytes -> 64 hex chars. Well beyond guessable, and URL-safe
        // without encoding, which keeps the invite link clean in an email
        // client that likes to mangle punctuation.
        $token = bin2hex(random_bytes(32));

        $invitation = DB::transaction(function () use ($organisation, $email, $role, $invitedBy, $token): Invitation {
            $organisation->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->delete();

            return Invitation::query()->create([
                'organisation_id' => $organisation->getKey(),
                'email' => $email,
                'role' => $role,
                'token_hash' => Invitation::hashToken($token),
                'invited_by_user_id' => $invitedBy?->getKey(),
                'expires_at' => now()->addDays(config('tenancy.invitation_expiry_days')),
            ]);
        });

        // Notified on-demand (not via the User model) because the invitee
        // usually has no account yet — there is no user row to notify.
        $invitation->notify(new OrganisationInvitation($invitation, $token, $organisation));

        return $invitation;
    }

    /**
     * Find a pending invitation by its plaintext token.
     *
     * Returns null for unknown, already-accepted and expired tokens alike. The
     * caller shows one generic "this invite link is no longer valid" message
     * for all three: distinguishing them would let anyone with a token probe
     * whether a given invitation ever existed.
     */
    public function findByToken(string $token): ?Invitation
    {
        return Invitation::query()
            ->pending()
            ->where('token_hash', Invitation::hashToken($token))
            ->first();
    }

    /**
     * Accept an invitation for a user who already has an account.
     *
     * Marking the invitation used and creating the membership happen in one
     * transaction so a crash between them cannot leave a consumed token with no
     * membership to show for it — the user would be locked out of a workspace
     * they were told they had joined, with no way to retry.
     */
    public function accept(Invitation $invitation, User $user): Membership
    {
        return DB::transaction(function () use ($invitation, $user): Membership {
            $membership = $this->organisations->addMember(
                $invitation->organisation,
                $user,
                $invitation->role,
                $invitation->invitedBy
            );

            $invitation->accepted_at = now();
            $invitation->accepted_user_id = $user->getKey();
            $invitation->save();

            return $membership;
        });
    }

    /**
     * Withdraw an outstanding invitation.
     *
     * Soft delete keeps the audit trail, and because findByToken() only looks
     * at non-deleted rows (SoftDeletes on BaseModel), the emailed link stops
     * working immediately.
     */
    public function revoke(Invitation $invitation): void
    {
        $invitation->delete();
    }
}
