<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Admin\Models\AuditLogEntry;
use Modules\Admin\Services\AuditLogger;
use Modules\Auth\Models\User;

/**
 * Back-office view of customer users, plus impersonation.
 *
 * Impersonation is the sharpest tool in the back office, so its constraints are
 * worth reading before touching it:
 *
 *   - It is **always** audited, before the session changes.
 *   - It logs the staff member OUT of the back office first. Holding an admin session
 *     and a customer session simultaneously invites acting on the wrong one, and
 *     forcing a fresh sign-in afterwards is a cheap way to make impersonation feel
 *     like the deliberate act it is.
 *   - There is no "impersonate and keep your admin session" convenience path, on
 *     purpose.
 *
 * This screen shows accounts and workspace membership only — never a customer's
 * transcripts or minutes. Staff who genuinely need to see a document impersonate,
 * which leaves a record.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Searchable list of customer accounts.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%");
            }))
            ->with('memberships.organisation')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin::users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    /**
     * One customer account.
     */
    public function show(string $user): View
    {
        $target = User::query()->with('memberships.organisation')->findOrFail($user);

        return view('admin::users.show', [
            'user' => $target,
            // What has been done TO this account from the back office — so a support
            // conversation can start from "here is everything we changed".
            'auditTrail' => AuditLogEntry::query()
                ->where('target_type', User::class)
                ->where('target_id', $target->getKey())
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Sign in as a customer to reproduce a problem they are reporting.
     *
     * Order matters and is not incidental: audit, then log out of admin, then log in
     * as the customer. Auditing first means the record exists even if something fails
     * midway; logging the admin out before logging the customer in means there is
     * never a moment where both sessions are live.
     */
    public function impersonate(Request $request, string $user): RedirectResponse
    {
        $target = User::query()->findOrFail($user);

        $validated = $request->validate([
            // Required: impersonation without a stated reason is exactly what an
            // audit log is supposed to prevent.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->audit->record(AuditLogger::USER_IMPERSONATED, $target, [
            'user_email' => $target->email,
            'reason' => $validated['reason'],
        ]);

        Auth::guard('admin')->logout();
        Auth::guard('web')->login($target);

        // Fresh session id across the identity switch.
        $request->session()->regenerate();

        return redirect()->route('core.dashboard')->with(
            'warning',
            'You are signed in as '.$target->email.' for support purposes. '
            .'Sign out and log in again to return to the back office.'
        );
    }
}
