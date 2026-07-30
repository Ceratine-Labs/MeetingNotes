<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Services\OrganisationContext;
use Modules\Tenancy\Services\SeatGuard;

/**
 * Validation for inviting a colleague into the current workspace.
 *
 * Two rules beyond the obvious field checks, both expressed as
 * after-validation hooks so they produce a normal field error under the email
 * input instead of an exception page:
 *
 *   1. The address must not already be a member — re-inviting an existing
 *      member is almost always a mistake, and silently succeeding would look
 *      like the invite went out when nothing happened.
 *   2. The plan's seat limit must have room. Enforced here (rather than only in
 *      the service) so the customer is told before they type an email and hit
 *      send, and told *why*, with a link to upgrade.
 */
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organisation = app(OrganisationContext::class)->get();

        if ($user === null || $organisation === null) {
            return false;
        }

        return (bool) $organisation->membershipFor($user)?->atLeast(Membership::ROLE_ADMIN);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'role' => ['required', Rule::in(Membership::ASSIGNABLE_ROLES)],
        ];
    }

    /**
     * Normalise before validation so the duplicate-member check below and the
     * eventual database write agree on casing — "Sam@acme.com" and
     * "sam@acme.com" are the same person.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organisation = app(OrganisationContext::class)->get();

            if ($organisation === null) {
                return;
            }

            $alreadyMember = $organisation->users()
                ->where('users.email', $this->input('email'))
                ->exists();

            if ($alreadyMember) {
                $validator->errors()->add('email', 'That person is already a member of this workspace.');

                // Return early: telling them the seat limit is also full would
                // be noise on top of a message that already explains it.
                return;
            }

            $seats = app(SeatGuard::class);

            if (! $seats->hasRoomFor($organisation)) {
                $validator->errors()->add('email', $seats->limitMessageFor($organisation));
            }
        });
    }
}
