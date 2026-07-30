<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;

/**
 * Payment and subscription records across all tenants.
 *
 * Read-only, deliberately. There is no "mark as paid" button and there never should
 * be: the payment provider is the source of truth for whether money moved, and a
 * button that lets staff assert otherwise turns the ledger into an opinion. To grant
 * access without payment, change the workspace's plan (which is audited and states a
 * reason) rather than fabricating a payment.
 */
class PaymentController extends Controller
{
    /**
     * Payment attempts, filterable by status and reference.
     */
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $payments = Payment::withoutOrganisationScope()
            ->with(['organisation', 'subscription'])
            ->when(
                in_array($status, [
                    Payment::STATUS_PENDING,
                    Payment::STATUS_SUCCESS,
                    Payment::STATUS_FAILED,
                    Payment::STATUS_ABANDONED,
                ], true),
                fn ($query) => $query->where('status', $status)
            )
            // Matches either reference, since reconciling against Paystack's
            // dashboard means searching by whichever one is to hand.
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('reference', 'ILIKE', "%{$search}%")
                    ->orWhere('paystack_reference', 'ILIKE', "%{$search}%");
            }))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin::payments.index', [
            'payments' => $payments,
            'status' => $status,
            'search' => $search,
        ]);
    }

    /**
     * One payment, including the raw provider payload.
     *
     * The payload is the evidence in a dispute, which is why it is kept whole and
     * shown here rather than reduced to a few parsed fields.
     */
    public function show(string $payment): View
    {
        return view('admin::payments.show', [
            'payment' => Payment::withoutOrganisationScope()
                ->with(['organisation', 'subscription'])
                ->findOrFail($payment),
        ]);
    }

    /**
     * Every subscription, newest first, filterable by status.
     *
     * Defaults to past-due when no filter is given: an unfiltered list is mostly
     * free-plan rows, whereas past-due subscriptions are the ones that need someone
     * to look at them before the grace period runs out.
     */
    public function subscriptions(Request $request): View
    {
        $status = $request->query('status', Subscription::STATUS_PAST_DUE);

        $subscriptions = Subscription::withoutOrganisationScope()
            ->with('organisation')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin::subscriptions.index', [
            'subscriptions' => $subscriptions,
            'status' => $status,
            'statuses' => [
                Subscription::STATUS_PAST_DUE => 'Payment failed',
                Subscription::STATUS_ACTIVE => 'Active',
                Subscription::STATUS_CANCELLED => 'Cancelled',
                Subscription::STATUS_EXPIRED => 'Expired',
                'all' => 'All',
            ],
        ]);
    }
}
