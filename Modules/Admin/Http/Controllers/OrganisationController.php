<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Admin\Services\AuditLogger;
use Modules\Billing\Models\GenerationUsage;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\QuotaService;
use Modules\Billing\Services\SubscriptionService;
use Modules\Minutes\Models\Meeting;
use Modules\Tenancy\Models\Organisation;

/**
 * Back-office view of customer workspaces.
 *
 * The support screen: who they are, what they are paying, how much they have used,
 * and the ability to move them onto a different plan by hand — for a comped
 * account, a bespoke arrangement, or to fix a billing mishap.
 *
 * Every tenant-owned query uses `withoutOrganisationScope()`, because reading across
 * tenants is the entire purpose of this controller. Note what is deliberately NOT
 * here: no view of transcript or minutes *content*. Staff can see that a workspace
 * generated 40 sets of minutes; they cannot read them. Support does not require
 * reading a customer's board minutes, and the privacy policy promises workspace
 * isolation.
 */
class OrganisationController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly QuotaService $quota,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Searchable list of workspaces.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $organisations = Organisation::query()
            ->when($search !== '', fn ($query) => $query->search($search, ['name', 'slug']))
            ->withCount('memberships')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // One query for every listed workspace's subscription, keyed for lookup in
        // the view — rather than N queries from inside the loop.
        $subscriptions = \Modules\Billing\Models\Subscription::withoutOrganisationScope()
            ->whereIn('organisation_id', $organisations->pluck('id'))
            ->live()
            ->get()
            ->keyBy('organisation_id');

        return view('admin::organisations.index', [
            'organisations' => $organisations,
            'subscriptions' => $subscriptions,
            'search' => $search,
        ]);
    }

    /**
     * One workspace in detail.
     */
    public function show(string $organisation): View
    {
        $target = Organisation::query()->findOrFail($organisation);

        return view('admin::organisations.show', [
            'organisation' => $target,
            'memberships' => $target->memberships()->with('user')->get(),
            'subscription' => $this->subscriptions->currentFor($target),
            'quota' => $this->quota->statusFor($target),

            'payments' => Payment::withoutOrganisationScope()
                ->where('organisation_id', $target->getKey())
                ->latest()
                ->limit(20)
                ->get(),

            // Counts and metadata only — never the minutes themselves.
            'meetingCount' => Meeting::withoutOrganisationScope()
                ->where('organisation_id', $target->getKey())
                ->count(),

            'usageThisPeriod' => (int) GenerationUsage::withoutOrganisationScope()
                ->where('organisation_id', $target->getKey())
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('credits'),

            'plans' => Plan::query()->active()->get(),
        ]);
    }

    /**
     * Move a workspace onto a different plan by hand.
     *
     * Deliberately does NOT touch Paystack. It grants or changes the entitlement in
     * our system only, which is what a comped account, a bespoke deal or a
     * compensating fix actually needs. If a customer has a live Paystack
     * subscription, that keeps billing them until it is cancelled separately — the
     * view says so, because the alternative (silently cancelling their recurring
     * payment as a side effect of a support action) is a much worse surprise.
     *
     * Audited with both the old and new plan, since this changes what a customer
     * receives for their money.
     */
    public function changePlan(Request $request, string $organisation): RedirectResponse
    {
        $target = Organisation::query()->findOrFail($organisation);

        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            // Required, not optional: a manual entitlement change should always
            // carry a stated reason for whoever reads the log later.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $plan = Plan::query()->where('code', $validated['plan_code'])->firstOrFail();
        $previous = $this->subscriptions->currentFor($target);

        $this->subscriptions->start($target, $plan);

        $this->audit->record(AuditLogger::ORGANISATION_PLAN_CHANGED, $target, [
            'from_plan' => $previous?->plan_code,
            'to_plan' => $plan->code,
            'reason' => $validated['reason'],
            'organisation_name' => $target->name,
        ]);

        return back()->with(
            'status',
            "{$target->name} is now on the {$plan->name} plan. Any Paystack subscription "
            .'was left untouched — cancel it there if this replaces a paid plan.'
        );
    }
}
