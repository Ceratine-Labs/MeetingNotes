<?php

namespace Modules\Billing\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\WebhookEvent;

/**
 * Applies Paystack webhook events to our own records.
 *
 * Webhooks — not the browser callback — are the reliable channel. The callback
 * only fires if the customer's browser makes it back; a closed tab, a dead
 * battery or a flaky mobile connection all skip it. Recurring renewals have no
 * browser involved at all. So the webhook is what keeps subscriptions correct
 * over time, and the callback is merely what makes the customer's own screen feel
 * immediate.
 *
 * Idempotency is a database guarantee, not a convention. `record()` inserts
 * against a unique index on (provider, event_id); a duplicate delivery loses the
 * insert race and is skipped. That is deliberately stronger than a
 * "have I seen this?" SELECT, which has a window between the check and the write
 * that two concurrent retries can both slip through.
 *
 * Signature verification happens in the controller before anything here runs.
 */
class WebhookProcessor
{
    /**
     * Paystack event types this processor understands.
     *
     * Anything else is recorded and marked processed without action — Paystack
     * sends a broad set, and treating an unhandled type as an error would fill the
     * log with noise and make the genuinely stuck events impossible to spot.
     *
     * @var list<string>
     */
    public const HANDLED = [
        'charge.success',
        'subscription.create',
        'subscription.disable',
        'subscription.not_renew',
        'invoice.payment_failed',
        'invoice.update',
    ];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Persist a received event, or detect that it is a duplicate.
     *
     * @param  array<string, mixed>  $payload  Decoded, signature-verified body.
     * @return WebhookEvent|null Null when this event has already been recorded, in
     *         which case the caller should acknowledge and do nothing.
     */
    public function record(array $payload): ?WebhookEvent
    {
        $eventType = (string) ($payload['event'] ?? 'unknown');

        // Paystack does not send a dedicated event id, so the transaction/
        // subscription id inside `data` is the stable key. Composed with the event
        // type because one transaction legitimately produces several different
        // events, and keying on the id alone would drop all but the first.
        $eventId = $this->deriveEventId($eventType, $payload);

        try {
            return WebhookEvent::query()->create([
                'provider' => 'paystack',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Expected on a retry. Not an error, and not worth an error-level log.
            Log::info('Duplicate Paystack webhook ignored', [
                'event_type' => $eventType,
                'event_id' => $eventId,
            ]);

            return null;
        }
    }

    /**
     * Apply an event's effect.
     *
     * Marks the row processed on success, or records the failure and leaves
     * `processed_at` null so Paystack's next retry (or a manual replay from the
     * admin UI) picks it up again.
     */
    public function process(WebhookEvent $event): void
    {
        try {
            match ($event->event_type) {
                'charge.success' => $this->handleChargeSuccess($event),
                'subscription.create' => $this->handleSubscriptionCreate($event),
                'subscription.disable', 'subscription.not_renew' => $this->handleSubscriptionDisabled($event),
                'invoice.payment_failed' => $this->handlePaymentFailed($event),
                // Recorded for the audit trail; carries no entitlement change.
                default => null,
            };

            $event->markProcessed();
        } catch (\Throwable $e) {
            // Caught rather than propagated: the controller must still return 200
            // quickly, and re-delivery is Paystack's job. report() puts it in front
            // of a human.
            report($e);
            $event->markFailed($e->getMessage());
        }
    }

    /**
     * A charge succeeded.
     *
     * Two cases arrive here. A **first** payment is normally already handled by the
     * callback (CheckoutService::complete), so this is the safety net for when the
     * customer's browser never came back. A **renewal** has no browser at all and
     * only ever arrives this way.
     */
    private function handleChargeSuccess(WebhookEvent $event): void
    {
        $reference = data_get($event->payload, 'data.reference');

        if (! is_string($reference)) {
            return;
        }

        $payment = Payment::withoutOrganisationScope()->where('reference', $reference)->first();

        if ($payment === null) {
            // A renewal charge Paystack generated itself — its reference is not one
            // of ours. Match it to the subscription instead.
            $this->handleRenewalCharge($event);

            return;
        }

        if ($payment->isSuccessful()) {
            return; // Callback got there first.
        }

        $payment->update([
            'status' => Payment::STATUS_SUCCESS,
            'paystack_reference' => $reference,
            'channel' => data_get($event->payload, 'data.channel'),
            'card_last4' => data_get($event->payload, 'data.authorization.last4'),
            'card_brand' => data_get($event->payload, 'data.authorization.brand'),
            'paid_at' => now(),
            'provider_payload' => data_get($event->payload, 'data'),
        ]);

        $planCode = data_get($event->payload, 'data.metadata.plan_code');
        $plan = is_string($planCode) ? Plan::query()->where('code', $planCode)->first() : null;

        if ($plan === null) {
            Log::error('charge.success webhook could not be matched to a plan', [
                'reference' => $reference,
                'plan_code' => $planCode,
            ]);

            return;
        }

        $current = $this->subscriptions->currentFor($payment->organisation);

        // Only start a new subscription if they are not already on this plan —
        // otherwise the callback and the webhook would each create one, leaving two
        // and an ambiguous entitlement.
        if ($current === null || $current->plan_code !== $plan->code) {
            $subscription = $this->subscriptions->start($payment->organisation, $plan, [
                'paystack_subscription_code' => data_get($event->payload, 'data.plan_object.subscription_code'),
                'paystack_customer_code' => data_get($event->payload, 'data.customer.customer_code'),
            ]);

            $payment->update(['subscription_id' => $subscription->getKey()]);
        }
    }

    /**
     * A recurring renewal charge, with a Paystack-generated reference.
     *
     * Matched by the subscription code, which is the only link back to us. Rolls
     * the metering period forward and clears any past-due state.
     */
    private function handleRenewalCharge(WebhookEvent $event): void
    {
        $subscription = $this->findSubscription($event);

        if ($subscription === null) {
            return;
        }

        $payment = Payment::query()->create([
            'organisation_id' => $subscription->organisation_id,
            'subscription_id' => $subscription->getKey(),
            'reference' => Payment::generateReference(),
            'amount_cents' => (int) data_get($event->payload, 'data.amount', $subscription->price_cents),
            'currency' => data_get($event->payload, 'data.currency', $subscription->currency),
            'status' => Payment::STATUS_SUCCESS,
            'paystack_reference' => data_get($event->payload, 'data.reference'),
            'channel' => data_get($event->payload, 'data.channel'),
            'card_last4' => data_get($event->payload, 'data.authorization.last4'),
            'card_brand' => data_get($event->payload, 'data.authorization.brand'),
            'paid_at' => now(),
            'provider_payload' => data_get($event->payload, 'data'),
        ]);

        $this->subscriptions->recordRenewal($subscription);

        Log::info('Subscription renewed from webhook', [
            'subscription_id' => $subscription->getKey(),
            'payment_id' => $payment->getKey(),
        ]);
    }

    /**
     * Paystack created the recurring subscription — store its codes.
     *
     * Both the subscription code and the email token are needed to cancel later,
     * and they only arrive in this event, so failing to capture them here means a
     * cancellation cannot be pushed to Paystack.
     */
    private function handleSubscriptionCreate(WebhookEvent $event): void
    {
        $customerCode = data_get($event->payload, 'data.customer.customer_code');
        $planCode = data_get($event->payload, 'data.plan.plan_code');

        if (! is_string($customerCode) || ! is_string($planCode)) {
            return;
        }

        // Matched on the customer code recorded at checkout, narrowed to the plan
        // in question in case one customer has held several.
        $subscription = Subscription::withoutOrganisationScope()
            ->where('paystack_customer_code', $customerCode)
            ->live()
            ->latest('created_at')
            ->first();

        if ($subscription === null) {
            Log::warning('subscription.create webhook for an unknown customer code', [
                'customer_code' => $customerCode,
            ]);

            return;
        }

        $subscription->update([
            'paystack_subscription_code' => data_get($event->payload, 'data.subscription_code'),
            'paystack_email_token' => data_get($event->payload, 'data.email_token'),
        ]);
    }

    /**
     * The recurring subscription stopped at Paystack's end.
     *
     * Marked cancelled rather than downgraded immediately: the customer has paid
     * through `current_period_end` and keeps access until then. The downgrade to
     * free happens lazily when the period elapses (QuotaService).
     */
    private function handleSubscriptionDisabled(WebhookEvent $event): void
    {
        $subscription = $this->findSubscription($event);

        if ($subscription === null || $subscription->isCancelled()) {
            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * A renewal charge failed.
     *
     * Starts the grace period. Access continues so that a card which expired over
     * a weekend does not read as churn.
     */
    private function handlePaymentFailed(WebhookEvent $event): void
    {
        $subscription = $this->findSubscription($event);

        if ($subscription === null) {
            return;
        }

        $this->subscriptions->markPastDue($subscription);

        Log::warning('Subscription renewal payment failed', [
            'subscription_id' => $subscription->getKey(),
            'organisation_id' => $subscription->organisation_id,
        ]);
    }

    /**
     * Find the subscription an event refers to.
     *
     * Tries the subscription code first (exact), then the customer code (an
     * organisation may have held several subscriptions, so the newest live one is
     * the right guess).
     */
    private function findSubscription(WebhookEvent $event): ?Subscription
    {
        $subscriptionCode = data_get($event->payload, 'data.subscription_code')
            ?? data_get($event->payload, 'data.subscription.subscription_code')
            ?? data_get($event->payload, 'data.plan_object.subscription_code');

        if (is_string($subscriptionCode) && $subscriptionCode !== '') {
            $found = Subscription::withoutOrganisationScope()
                ->where('paystack_subscription_code', $subscriptionCode)
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        $customerCode = data_get($event->payload, 'data.customer.customer_code');

        if (is_string($customerCode) && $customerCode !== '') {
            return Subscription::withoutOrganisationScope()
                ->where('paystack_customer_code', $customerCode)
                ->live()
                ->latest('created_at')
                ->first();
        }

        Log::warning('Paystack webhook could not be matched to a subscription', [
            'event_type' => $event->event_type,
            'event_id' => $event->event_id,
        ]);

        return null;
    }

    /**
     * Build the idempotency key for an event.
     *
     * Paystack sends no dedicated event id, so we compose one from the event type
     * and the most specific identifier in the payload. Including the type matters:
     * a single transaction produces charge.success AND subscription.create, and
     * keying on the id alone would silently drop the second.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deriveEventId(string $eventType, array $payload): string
    {
        $identifier = data_get($payload, 'data.id')
            ?? data_get($payload, 'data.reference')
            ?? data_get($payload, 'data.subscription_code')
            // Last resort: hash the payload. Two genuinely identical payloads are
            // a duplicate delivery anyway, which is exactly what we want to drop.
            ?? substr(hash('sha256', json_encode($payload)), 0, 32);

        return $eventType.':'.$identifier;
    }
}
