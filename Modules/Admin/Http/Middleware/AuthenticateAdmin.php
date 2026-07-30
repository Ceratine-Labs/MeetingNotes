<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every /admin route.
 *
 * Three jobs, and the second and third are the ones that are easy to forget:
 *
 *   1. Require an authenticated `admin` guard session — not a `web` one. A signed-in
 *      customer has no standing here at all.
 *   2. Re-check that the account is still active on every request. Deactivating an
 *      admin must take effect immediately, not whenever their session happens to
 *      expire.
 *   3. Redirect guests to the ADMIN login page. The application-wide
 *      `redirectGuestsTo` in bootstrap/app.php points at the customer login, which
 *      would be both confusing and a small information leak about how the back
 *      office is reached.
 *
 * Registered as the `admin.auth` alias. The old `admin` alias is the legacy
 * user-role gate in the Auth module and is a different thing — the names are kept
 * distinct so a route cannot accidentally use the weaker one.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if ($admin === null) {
            return redirect()->guest(route('admin.login'));
        }

        // Deactivated or soft-deleted mid-session: end it now rather than letting an
        // existing session outlive the revocation.
        if (! $admin->canAuthenticate()) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->withErrors(['email' => 'That back-office account is no longer active.']);
        }

        return $next($request);
    }
}
