<?php

namespace Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Services\OrganisationContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the signed-in user to hold at least a given role in the current
 * organisation.
 *
 * Applied as `organisation.role:admin` or `organisation.role:owner`. Roles are
 * a hierarchy (see Membership::atLeast), so `:admin` also admits the owner.
 *
 * Must run after `organisation`, which is what binds the organisation this
 * checks membership against.
 */
class EnsureOrganisationRole
{
    public function __construct(private readonly OrganisationContext $context) {}

    /**
     * @param  string  $role  One of the Membership::ROLE_* values.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $organisation = $this->context->get();

        if ($user === null || $organisation === null) {
            return redirect()->route('auth.login');
        }

        $membership = $organisation->membershipFor($user);

        // Fails closed on a missing membership as well as an insufficient one.
        if ($membership === null || ! $membership->atLeast($role)) {
            abort(Response::HTTP_FORBIDDEN, "This action requires the {$role} role in this workspace.");
        }

        return $next($request);
    }
}
