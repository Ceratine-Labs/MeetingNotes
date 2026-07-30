<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Admin\Models\AuditLogEntry;
use Modules\Billing\Models\GenerationUsage;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\WebhookEvent;
use Modules\Minutes\Models\Meeting;
use Modules\Tenancy\Models\Organisation;

/**
 * Back-office overview: the numbers that tell Ryan whether the business is working
 * and whether anything is broken.
 *
 * Every query here reads across all tenants, which is legitimate for the back office
 * and is why each tenant-owned model is queried through
 * `withoutOrganisationScope()`. That method is verbose on purpose — a reader should
 * see immediately that tenant isolation has been switched off deliberately rather
 * than by omission.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin::dashboard', [
            'counts' => $this->counts(),
            'revenue' => $this->revenue(),
            'recentPayments' => $this->recentPayments(),
            'failedWebhooks' => $this->failedWebhooks(),
            'recentAudit' => AuditLogEntry::query()->with('admin')->latest()->limit(10)->get(),
            'planBreakdown' => $this->planBreakdown(),
            'billingEnabled' => (bool) config('billing.enabled'),
        ]);
    }

    /**
     * Headline counts.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'organisations' => Organisation::query()->count(),
            'users' => \Modules\Auth\Models\User::query()->count(),
            'meetings' => Meeting::withoutOrganisationScope()->count(),

            // Paying = a live subscription that actually costs money. Free-plan
            // subscriptions are the overwhelming majority and would drown the
            // number that matters.
            'paying' => Subscription::withoutOrganisationScope()
                ->live()
                ->where('price_cents', '>', 0)
                ->count(),

            // Needs attention: a renewal has failed and the grace period is running.
            'past_due' => Subscription::withoutOrganisationScope()
                ->where('status', Subscription::STATUS_PAST_DUE)
                ->count(),

            'generations_this_month' => (int) GenerationUsage::withoutOrganisationScope()
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('credits'),
        ];
    }

    /**
     * Revenue actually collected, in cents.
     *
     * Only `success` payments are summed — pending and failed rows are not money.
     *
     * @return array{this_month: int, last_month: int, all_time: int}
     */
    private function revenue(): array
    {
        $successful = fn () => Payment::withoutOrganisationScope()
            ->where('status', Payment::STATUS_SUCCESS);

        return [
            'this_month' => (int) $successful()
                ->where('paid_at', '>=', now()->startOfMonth())
                ->sum('amount_cents'),

            'last_month' => (int) $successful()
                ->whereBetween('paid_at', [
                    now()->subMonthNoOverflow()->startOfMonth(),
                    now()->subMonthNoOverflow()->endOfMonth(),
                ])
                ->sum('amount_cents'),

            'all_time' => (int) $successful()->sum('amount_cents'),
        ];
    }

    /**
     * Latest payment attempts across all tenants, successful or not.
     *
     * Failures are included deliberately — a run of declines is exactly what should
     * be visible here.
     *
     * @return \Illuminate\Support\Collection<int, Payment>
     */
    private function recentPayments(): \Illuminate\Support\Collection
    {
        return Payment::withoutOrganisationScope()
            ->with('organisation')
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * Webhook events that were received but never handled.
     *
     * `processed_at IS NULL` with at least one attempt means handling threw. These
     * are the events that can silently leave a customer on the wrong plan, so they
     * belong on the front page rather than buried in a log.
     *
     * @return \Illuminate\Support\Collection<int, WebhookEvent>
     */
    private function failedWebhooks(): \Illuminate\Support\Collection
    {
        return WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('attempts', '>', 0)
            ->latest()
            ->limit(5)
            ->get();
    }

    /**
     * How many live subscriptions sit on each plan.
     *
     * Grouped in SQL rather than in PHP so the whole subscriptions table is not
     * pulled into memory as the customer base grows.
     *
     * @return \Illuminate\Support\Collection<int, object{plan_name: string, total: int}>
     */
    private function planBreakdown(): \Illuminate\Support\Collection
    {
        return Subscription::withoutOrganisationScope()
            ->live()
            ->select('plan_name', DB::raw('COUNT(*) AS total'))
            ->groupBy('plan_name')
            ->orderByDesc('total')
            ->get();
    }
}
