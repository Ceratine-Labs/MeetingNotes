<?php

namespace Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Modules\Tenancy\Services\OrganisationResolver;

/**
 * Binds the user's current organisation for the request.
 *
 * **Every route that reads or writes tenant data must run through this.** The
 * OrganisationScope throws on an unbound context during a web request rather
 * than falling back to unfiltered results, so a route registered outside this
 * middleware fails loudly in development instead of leaking another customer's
 * data in production. That is the intended safety net, not a nuisance — if you
 * hit MissingOrganisationContextException, add the middleware, do not weaken
 * the scope.
 *
 * Registered as the `organisation` alias by TenancyServiceProvider and applied
 * after `auth`, since resolution needs a user.
 */
class EnsureOrganisation
{
    public function __construct(private readonly OrganisationResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Defence in depth: `auth` should already have handled this, but the
        // resolver requires a user and a null here would be a confusing
        // TypeError deep in the stack rather than a redirect to login.
        if ($user === null) {
            return redirect()->route('auth.login');
        }

        if ($this->resolver->resolveFor($user) === null) {
            // A signed-in user with no organisation at all. Normally
            // unreachable — registration creates one — but it is reachable if
            // they were removed from their only workspace while logged in.
            // Send them somewhere they can make a new one instead of showing
            // an error they cannot act on.
            return redirect()
                ->route('tenancy.organisations.create')
                ->with('info', 'You are not a member of any workspace yet. Create one to continue.');
        }

        return $next($request);
    }
}
