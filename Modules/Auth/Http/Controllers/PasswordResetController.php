<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgotten-password and reset flow for end users.
 *
 * Built on Laravel's password broker (the `users` broker, backed by the
 * `password_reset_tokens` table) rather than hand-rolled: the broker already
 * handles token hashing, single-use tokens and expiry, and getting any of those
 * wrong is a full account-takeover bug.
 *
 * Note the enumeration stance, which differs between the two halves on purpose:
 *
 *   - **Requesting** a link always reports success, even for an address with no
 *     account. Otherwise the form becomes a free "does this person use
 *     MeetingNotes?" lookup.
 *   - **Submitting** a reset does report a specific failure, because by then the
 *     user holds a token from their own inbox and needs to know whether it has
 *     expired.
 */
class PasswordResetController extends Controller
{
    /**
     * "Forgot your password?" form.
     */
    public function showLinkRequest(): View
    {
        return view('auth::passwords.email');
    }

    /**
     * Email a reset link.
     *
     * Always reports the same success message. Rate limiting on the route stops
     * this being used to mailbomb an address.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Deliberately ignoring the broker's return value. INVALID_USER means
        // no such account, and surfacing that difference is exactly the leak
        // we are avoiding.
        Password::sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'If an account exists for that address, a password reset link is on its way.'
        );
    }

    /**
     * Reset form, reached from the emailed link.
     */
    public function showReset(Request $request, string $token): View
    {
        return view('auth::passwords.reset', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Apply a new password.
     *
     * The broker validates the token, then the callback sets the password. Two
     * details in that callback matter:
     *
     *   - A fresh remember_token invalidates any "remember me" cookie issued
     *     before the reset. Without it, an attacker who set one keeps their
     *     access after the victim changes their password.
     *   - The PasswordReset event is what Laravel's own listener uses; firing it
     *     keeps behaviour consistent with the framework's expectations.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()
            ->route('auth.login')
            ->with('status', 'Your password has been reset. You can sign in now.');
    }
}
