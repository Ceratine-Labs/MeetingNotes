<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Admin\Models\AdminUser;
use Modules\Admin\Services\AuditLogger;

/**
 * Sign in and out of the back office, and the staff password reset flow.
 *
 * Entirely separate from the customer AuthController: different guard, different
 * table, different password broker, different routes. Nothing is shared, which is
 * the point — see the v1__01_admin_tables migration.
 *
 * There is no registration. Back-office accounts are provisioned by the seed master
 * or the `admin:create` command, never self-served.
 */
class AdminAuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin::auth.login');
    }

    /**
     * Attempt a back-office sign-in.
     *
     * Every failure is audited, with the attempted address. Failed customer logins
     * are noise; failed back-office logins are a signal, and the only way to notice
     * someone probing this endpoint is to have recorded the attempts.
     *
     * The active check runs after the credential check so that a deactivated
     * account with a wrong password reveals nothing more than a wrong password
     * would.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            $this->audit->record(
                AuditLogger::LOGIN_FAILED,
                context: ['reason' => 'invalid_credentials'],
                actorEmail: $credentials['email'],
            );

            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        if (! $admin->canAuthenticate()) {
            Auth::guard('admin')->logout();

            $this->audit->record(
                AuditLogger::LOGIN_FAILED,
                $admin,
                ['reason' => 'account_inactive'],
                $admin->email,
            );

            throw ValidationException::withMessages([
                'email' => 'That back-office account is not active.',
            ]);
        }

        // New session id on privilege change — closes off session fixation.
        $request->session()->regenerate();

        $admin->recordLogin($request->ip());
        $this->audit->record(AuditLogger::LOGIN, $admin);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Sign out of the back office.
     *
     * Only the `admin` guard is logged out. If the same browser also holds a
     * customer session, it is deliberately left alone — the two are independent, and
     * silently signing someone out of the product because they left the back office
     * would be surprising.
     */
    public function logout(Request $request): RedirectResponse
    {
        $this->audit->record(AuditLogger::LOGOUT);

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Signed out of the back office.');
    }

    /**
     * "Forgot password" form for staff.
     */
    public function showLinkRequest(): View
    {
        return view('admin::auth.forgot-password');
    }

    /**
     * Email a staff reset link.
     *
     * Uses the `admins` broker explicitly — the default broker would look in the
     * customer `users` table and find nothing.
     *
     * Reports the same message whatever happens, so this endpoint cannot be used to
     * discover which addresses are staff accounts. That matters more here than on
     * the customer form: knowing who the administrators are is step one of targeting
     * them.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker('admins')->sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'If that address belongs to a back-office account, a reset link is on its way.'
        );
    }

    /**
     * Staff reset form, reached from the emailed link.
     */
    public function showReset(Request $request, string $token): View
    {
        return view('admin::auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Apply a new staff password.
     *
     * A 12-character minimum, longer than the customers' 10: these accounts can read
     * every customer's data, so the extra friction is justified here in a way it is
     * not on a sign-up form.
     *
     * The fresh remember_token invalidates any "remember me" cookie issued before
     * the reset — without it, an attacker who set one keeps their access after the
     * password changes.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()->symbols()],
        ]);

        $status = Password::broker('admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (AdminUser $admin, string $password): void {
                $admin->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($admin));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()
            ->route('admin.login')
            ->with('status', 'Password updated. You can sign in now.');
    }
}
