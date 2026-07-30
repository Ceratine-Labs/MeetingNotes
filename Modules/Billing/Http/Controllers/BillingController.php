<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Billing\Exceptions\GatewayException;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\CheckoutService;
use Modules\Billing\Services\QuotaService;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Services\OrganisationContext;

/**
 * The customer-facing billing screens: current plan, usage, plan picker,
 * checkout, and cancellation.
 *
 * Everything here is owner-only (`organisation.role:owner`), except the Paystack
 * callback — see the route file for why that one is different.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly OrganisationContext $context,
        private readonly SubscriptionService $subscriptions,
        private readonly QuotaService $quota,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * Billing overview: what they are on, what they have used, payment history.
     */
    public function index(): View
    {
        $organisation = $this->context->getOrFail();

        return view('billing::index', [
            'organisation' => $organisation,
            'subscription' => $this->subscriptions->currentFor($organisation),
            'quota' => $this->quota->statusFor($organisation),
            'payments' => Payment::query()
                ->where('organisation_id', $organisation->getKey())
                ->latest()
                ->limit(20)
                ->get(),
            'billingEnabled' => (bool) config('billing.enabled'),
        ]);
    }

    /**
     * Plan picker.
     *
     * Public plans only, so a bespoke or grandfathered plan stays subscribable for
     * the customers already on it without appearing in the list.
     */
    public function plans(): View
    {
        $organisation = $this->context->getOrFail();

        return view('billing::plans', [
            'plans' => Plan::query()->public()->get(),
            'subscription' => $this->subscriptions->currentFor($organisation),
            'billingEnabled' => (bool) config('billing.enabled'),
        ]);
    }

    /**
     * Start a checkout for the chosen plan.
     *
     * Redirects to Paystack rather than rendering a card form: no card details ever
     * touch this application, which keeps PCI scope essentially nil.
     */
    public function checkout(Request $request, string $plan): RedirectResponse
    {
        if (! config('billing.enabled')) {
            return back()->with('error', 'Paid plans are not available yet.');
        }

        $organisation = $this->context->getOrFail();
        $target = Plan::query()->where('code', $plan)->active()->firstOrFail();

        try {
            $url = $this->checkout->begin($organisation, $target, $request->user());
        } catch (\DomainException $e) {
            // A rule the customer could plausibly have tripped (free plan, or a
            // plan not yet pushed to Paystack) — show it rather than 500.
            return back()->with('error', $e->getMessage());
        } catch (GatewayException $e) {
            // Paystack is unreachable or rejected us. The customer cannot act on
            // the detail, so they get a plain message and we get the log entry.
            report($e);

            return back()->with('error', 'We could not start the payment just now. Please try again shortly.');
        }

        // away() rather than redirect(): the target is an external host, and
        // redirect() would try to resolve it against our own routes.
        return redirect()->away($url);
    }

    /**
     * Where Paystack returns the customer's browser after payment.
     *
     * The reference in the query string is untrusted — anyone can visit this URL
     * with an invented one. CheckoutService::complete() verifies server-side
     * against Paystack's API before anything is marked paid.
     *
     * This page is a courtesy for the customer's own screen; the authoritative
     * record comes from the webhook, which fires whether or not the browser makes
     * it back here.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference');

        if (! is_string($reference) || $reference === '') {
            return redirect()->route('billing.index')
                ->with('error', 'That payment could not be confirmed.');
        }

        try {
            $subscription = $this->checkout->complete($reference);
        } catch (GatewayException $e) {
            report($e);

            // Deliberately not "your payment failed" — we do not know that. The
            // webhook will settle it, so the honest message is that confirmation
            // is pending.
            return redirect()->route('billing.index')->with(
                'warning',
                'We could not confirm your payment immediately. If it went through, '
                .'your plan will update within a few minutes.'
            );
        }

        if ($subscription === null) {
            return redirect()->route('billing.plans')->with(
                'error',
                'That payment did not complete. You have not been charged.'
            );
        }

        return redirect()->route('billing.index')->with(
            'status',
            "You are now on the {$subscription->plan_name} plan."
        );
    }

    /**
     * Cancel the subscription.
     *
     * Access continues to the end of the paid period — they have paid for that
     * time — and the drop to free happens when it elapses. Nothing is deleted.
     */
    public function cancel(): RedirectResponse
    {
        $organisation = $this->context->getOrFail();
        $subscription = $this->subscriptions->currentFor($organisation);

        if ($subscription === null || $subscription->isFree()) {
            return back()->with('error', 'There is no paid subscription to cancel.');
        }

        if ($subscription->isCancelled()) {
            return back()->with('info', 'That subscription is already cancelled.');
        }

        $this->subscriptions->cancel($subscription);

        return back()->with('status', sprintf(
            'Your subscription is cancelled. You keep %s features until %s, then move to the free plan.',
            $subscription->plan_name,
            $subscription->current_period_end->toFormattedDayDateString()
        ));
    }
}
