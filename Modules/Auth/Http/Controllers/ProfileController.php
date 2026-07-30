<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * The signed-in user's own account settings: name, email address, password.
 *
 * Workspace settings are a different screen with different permissions (see
 * Tenancy) — this is strictly "about me", so no role checks are needed beyond
 * being signed in.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth::profile', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update name and email.
     *
     * Changing the email un-verifies the account and sends a fresh verification
     * link. Skipping that would let someone move their account to an address
     * they do not control while keeping a verified badge — and, once verification
     * gates generation, quietly bypass the check.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required', 'email:rfc,dns', 'max:255',
                // Ignore their own row, or saving the form without touching the
                // email would fail the unique check against themselves.
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
        ]);

        $validated['email'] = mb_strtolower(trim($validated['email']));
        $emailChanged = $validated['email'] !== $user->email;

        $user->fill($validated);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();

            return back()->with(
                'status',
                'Profile saved. Check your new address for a verification link.'
            );
        }

        return back()->with('status', 'Profile saved.');
    }

    /**
     * Change password.
     *
     * Requires the current password even though the user is already signed in:
     * it stops someone who walked up to an unlocked laptop from taking the
     * account over, which is the realistic threat here.
     *
     * logoutOtherDevices invalidates every other session by rotating the
     * password hash the session guard checks against — so a change made because
     * "I think someone has my password" actually evicts them.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }

        $request->user()->update(['password' => $validated['password']]);

        auth()->logoutOtherDevices($validated['password']);

        return back()->with('status', 'Password changed. Other devices have been signed out.');
    }
}
