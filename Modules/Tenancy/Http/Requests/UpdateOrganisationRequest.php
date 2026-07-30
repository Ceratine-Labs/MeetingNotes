<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Services\OrganisationContext;

/**
 * Validation for editing the current workspace's settings.
 *
 * Authorisation is re-checked here even though the route already carries
 * `organisation.role:admin`. Belt and braces on a write path is cheap, and it
 * means the rule survives someone reorganising the route file.
 */
class UpdateOrganisationRequest extends FormRequest
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }
}
