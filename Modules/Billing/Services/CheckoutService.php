<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Models\User;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\GatewayException;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Tenancy\Models\Organisation;

/**
 * Taking a customer from "I want the Team plan" to an active subscription.
 *
 * The flow, and why each step exists:
 *
 *   1. `begin()` writes a **pending** Payment row, then asks Paystack for a
 *      checkout URL. Recording our intent before redirecting is what makes an
 *      abandoned checkout visible — otherwise a customer who reaches the payment
 *      page and closes the tab leaves no trace, and "I'm sure I paid" becomes
 *      unanswerable.
 *
 *   2. The customer pays at Paystack and is returned to `complete()`.
 *
 *   3. `complete()` **verifies server-side** before believing anything. The
 *      browser callback's query string is attacker-controlled: anyone can visit
 *      the callback URL with a made-up reference. Only Paystack's own API
 *      response may mark a payment successful.
 *
 *   4. The amount is checked against what we asked for, then the subscription is
 *      started.
 */
class CheckoutService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Start a checkout and return the URL to redirect the customer to.
     *
     * @param  User  $user  Whoever is paying; Paystack keys its customer record on
     *                      their email.
     * @return string Paystack authorization URL.
     *
     * @throws GatewayException When Paystack cannot be reached or rejects the
     *         request. The pending Payment row is rolled back, so a failed start
     *         does not litter the ledger with rows that can never complete.
     * @throws \DomainException When the plan cannot be checked out at all.
     */
    public function begin(Organisation $organisation, Plan $plan, User $user): string
    {
        if ($plan->isFree()) {
            throw new \DomainException('The free plan does not require a checkout.');
        }

        if (! $plan->isBillable()) {
            // A paid plan with no paystack_plan_code has not been pushed to
            // Paystack. Better a clear error than a checkout that charges once and
            // never renews.
            throw new \DomainException(
                "Plan [{$plan->code}] is not set up for recurring billing yet. "
                .'Push it to Paystack from the admin plan editor first.'
            );
        }

        $reference = Payment::generateReference();

        // Transaction: if Paystack throws, the pending row goes away with it.
        return DB::transaction(function () use ($organisation, $plan, $user, $reference): string {
            Payment::query()->create([
                'organisation_id' => $organisation->getKey(),
                'reference' => $reference,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'status' => Payment::STATUS_PENDING,
            ]);

            $transaction = $this->gateway->initialiseTransaction(
                email: $user->email,
                amountCents: $plan->price_cents,
                reference: $reference,
                callbackUrl: route('billing.callback'),
                planCode: $plan->paystack_plan_code,
                // Echoed back on verification and in webhooks. It is a
                // convenience for reconciliation, NOT a trust boundary — the
                // authoritative link is our `reference` on the Payment row, since
                // metadata round-trips through the customer's browser.
                metadata: [
                    'organisation_id' => $organisation->getKey(),
                    'plan_code' => $plan->code,
                    'user_id' => $user->getKey(),
                ],
            );

            return $transaction->authorizationUrl;
        });
    }

    /**
     * Verify a returned checkout and activate the subscription.
     *
     * @param  string  $reference  From the callback query string. Untrusted — it is
     *         used only to look up our own pending row and to ask Paystack about
     *         it; nothing is believed on its word.
     * @return Subscription|null The new subscription, or null when the payment did
     *         not succeed (the caller shows a "payment failed" message).
     *
     * @throws GatewayException When verification cannot be performed at all.
     */
    public function complete(string $reference): ?Subscription
    {
        $payment = Payment::withoutOrganisationScope()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            // A reference we never issued. Almost certainly someone poking the
            // callback URL by hand.
            Log::warning('Payment callback for an unknown reference', ['reference' => $reference]);

            return null;
        }

        // Already handled — the webhook usually beats the browser back. Return the
        // existing subscription so a refreshed callback page shows success rather
        // than starting a second subscription.
        if ($payment->isSuccessful()) {
            return $this->subscriptions->currentFor($payment->organisation);
        }

        $transaction = $this->gateway->verifyTransaction($reference);

        if (! $transaction->successful) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'paystack_reference' => $transaction->providerReference,
                'failure_reason' => $transaction->failureReason,
                'provider_payload' => $transaction->raw,
            ]);

            return null;
        }

        // Amount check. A mismatch means either tampering or a currency mix-up,
        // and activating a subscription on a short payment is exactly the bug this
        // catches. Recorded as failed and flagged for a human.
        if ($transaction->amountCents !== $payment->amount_cents) {
            Log::error('Paystack payment amount does not match the expected amount', [
                'reference' => $reference,
                'expected_cents' => $payment->amount_cents,
                'received_cents' => $transaction->amountCents,
            ]);

            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'paystack_reference' => $transaction->providerReference,
                'failure_reason' => 'Amount mismatch — flagged for review.',
                'provider_payload' => $transaction->raw,
            ]);

            return null;
        }

        $plan = Plan::query()->where('code', data_get($transaction->raw, 'metadata.plan_code'))->first();

        if ($plan === null) {
            // Verified payment we cannot map to a plan. Never silently discard
            // money: record the success and let a human sort out the entitlement.
            Log::error('Verified Paystack payment could not be matched to a plan', [
                'reference' => $reference,
                'metadata' => data_get($transaction->raw, 'metadata'),
            ]);

            $payment->update([
                'status' => Payment::STATUS_SUCCESS,
                'paystack_reference' => $transaction->providerReference,
                'paid_at' => now(),
                'provider_payload' => $transaction->raw,
                'failure_reason' => 'Paid, but no matching plan — entitlement not granted. Needs review.',
            ]);

            return null;
        }

        return DB::transaction(function () use ($payment, $plan, $transaction): Subscription {
            $subscription = $this->subscriptions->start($payment->organisation, $plan, [
                'paystack_subscription_code' => $transaction->subscriptionCode,
                'paystack_customer_code' => $transaction->customerCode,
                'paystack_email_token' => $transaction->emailToken,
            ]);

            $payment->update([
                'subscription_id' => $subscription->getKey(),
                'status' => Payment::STATUS_SUCCESS,
                'paystack_reference' => $transaction->providerReference,
                'channel' => $transaction->channel,
                'card_last4' => $transaction->cardLast4,
                'card_brand' => $transaction->cardBrand,
                'paid_at' => now(),
                'provider_payload' => $transaction->raw,
            ]);

            return $subscription;
        });
    }
}
