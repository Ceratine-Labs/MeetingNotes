<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign in and sign out for end users (the `web` guard).
 *
 * The SaaS back office has its own controller, guard and login path — see
 * Modules/Admin. The two are kept entirely separate so that no bug here can
 * ever produce a back-office session.
 */
class AuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('core.dashboard');
        }

        return view('auth::login', [
            // Carried through from an invite link so that logging in returns
            // the user to the invitation they were accepting.
            'invitationToken' => $request->query('invitation'),
        ]);
    }

    /**
     * Attempt a sign-in.
     *
     * Failure returns one deliberately vague message on the email field. Saying
     * "no account with that email" versus "wrong password" hands an attacker a
     * free account-enumeration oracle.
     *
     * Throttling is on the route (`throttle:login`), not here, so a lockout
     * response never reaches the credential check at all.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        // New session id on privilege change — closes off session fixation,
        // where an attacker plants a known session id before the victim logs in.
        $request->session()->regenerate();

        $request->user()->recordLogin($request->ip());

        // An invite token on the login link means they were mid-acceptance;
        // send them back to finish rather than dropping them on the dashboard.
        $invitation = $request->input('invitation');

        if (is_string($invitation) && $invitation !== '') {
            return redirect()->route('tenancy.invitations.show', ['token' => $invitation]);
        }

        return redirect()->intended(route('core.dashboard'));
    }

    /**
     * Sign out and dispose of the session.
     *
     * All three steps matter: logout() clears the auth state, invalidate()
     * destroys the session data, and regenerateToken() issues a fresh CSRF
     * token so the login form the user lands on is not carrying the old one.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('site.home')->with('status', 'You have been signed out.');
    }
}
