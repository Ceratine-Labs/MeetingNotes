<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Auth\Http\Requests\RegisterRequest;
use Modules\Auth\Services\RegistrationService;
use Modules\Tenancy\Services\OrganisationResolver;

/**
 * Self-service registration.
 *
 * No payment step and no card: registration always lands on the permanent free
 * plan, which Billing provisions in response to the OrganisationCreated event.
 * That is a deliberate product decision and it has a useful engineering
 * consequence — **registration works with Paystack unconfigured**, so the
 * product can be deployed and used before billing credentials exist.
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly OrganisationResolver $resolver,
    ) {}

    /**
     * Show the registration form.
     *
     * Handles the invited-colleague case: when an `invitation` token is present
     * and still pending, the workspace-name field is hidden (they are joining an
     * existing workspace, not creating one) and the email is pre-filled from the
     * invitation.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('core.dashboard');
        }

        $token = $request->query('invitation');
        $invitation = $this->registration->pendingInvitation(is_string($token) ? $token : null);

        return view('auth::register', [
            'invitation' => $invitation,
            'invitationToken' => $invitation !== null ? $token : null,
            // Pre-fill from the invitation, otherwise from ?email= on the link,
            // otherwise blank. old() still wins on a validation redisplay.
            'prefilledEmail' => $invitation?->email ?? $request->query('email'),
        ]);
    }

    /**
     * Create the account, sign them in, and drop them straight into the app.
     *
     * Signing in immediately is intentional: making someone who just chose a
     * password type it again is friction with no security benefit.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $result = $this->registration->register(
            $request->safe()->only(['name', 'email', 'password', 'organisation_name']),
            $request->validated('invitation')
        );

        Auth::login($result['user'], remember: true);

        // Fresh session id after a privilege change, to close off session
        // fixation — same reason the login flow regenerates.
        $request->session()->regenerate();

        // Bind the workspace now so the very first authenticated page load has
        // an organisation in context rather than relying on the middleware to
        // discover it.
        $this->resolver->switchTo($result['user'], $result['organisation']->getKey());

        $message = $result['joined_via_invitation']
            ? "Welcome — you have joined {$result['organisation']->name}."
            : 'Welcome to '.config('app.name').'. Paste a transcript to generate your first minutes.';

        return redirect()->route('core.dashboard')->with('status', $message);
    }
}
