<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Email address verification.
 *
 * Verification does NOT gate signing in — a new customer can look around
 * immediately. It gates the first *generation*, which is the expensive and
 * abusable action (each one costs real LLM spend, and the free tier hands them
 * out without a card). The `verified` middleware sits on the generation route
 * only; everything else in the app is reachable unverified.
 *
 * The signed-URL verification itself is Laravel's: EmailVerificationRequest
 * validates the signature, the id and the hash before this controller runs.
 */
class EmailVerificationController extends Controller
{
    /**
     * "Check your inbox" page — where the `verified` middleware sends someone
     * who has not verified yet.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('core.dashboard');
        }

        return view('auth::verify-email');
    }

    /**
     * Mark the address verified.
     *
     * EmailVerificationRequest has already authorised the signed link. The
     * already-verified branch exists because people click the link twice (or a
     * mail client prefetches it), and that must be a no-op, not an error.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('core.dashboard');
        }

        $request->user()->markEmailAsVerified();

        event(new Verified($request->user()));

        return redirect()
            ->route('core.dashboard')
            ->with('status', 'Email confirmed. You are all set.');
    }

    /**
     * Send another verification email.
     *
     * Rate limited on the route — this endpoint sends mail on demand to an
     * address we already hold, so without a throttle it is a mailbomb button.
     */
    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('core.dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link is on its way.');
    }
}
