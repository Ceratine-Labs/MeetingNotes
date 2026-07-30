<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Tenancy\Models\Membership;

/**
 * Validation for creating a workspace.
 *
 * The abuse limit lives here rather than in the service because it is a rule
 * about *this request* (a signed-in user creating another workspace for
 * themselves), not about organisations in general — the admin back office and
 * the registration flow both create organisations without it applying.
 */
class StoreOrganisationRequest extends FormRequest
{
    /**
     * Any signed-in user may create a workspace, up to the abuse cap.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $owned = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('role', Membership::ROLE_OWNER)
            ->count();

        return $owned < config('tenancy.max_organisations_per_user');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // No unique rule on name: two unrelated customers may both be
            // called "Acme". Uniqueness is on the generated slug, which the
            // service resolves with a numeric suffix.
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the workspace a name — usually your company or team name.',
        ];
    }
}
