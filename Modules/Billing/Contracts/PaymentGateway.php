<?php

namespace Modules\Billing\Contracts;

use Modules\Billing\Support\GatewayPlan;
use Modules\Billing\Support\GatewayTransaction;

/**
 * What the billing module needs from a payment service provider.
 *
 * Paystack is the only implementation today. The interface exists so that adding
 * a second PSP (or a fake for tests) is a new class rather than a hunt through
 * controllers for `Paystack::` calls — and so the return shapes are ours
 * (GatewayTransaction, GatewayPlan) rather than raw provider JSON leaking into
 * services and views.
 *
 * Implementations must:
 *   - throw GatewayException on any transport or API failure, never return null
 *     or a partially-populated object;
 *   - treat all amounts as integer minor units (cents), matching the database;
 *   - never log a secret key or a full card number.
 */
interface PaymentGateway
{
    /**
     * Begin a checkout and get the URL to send the customer to.
     *
     * @param  string  $email  The paying user's address; the PSP keys its customer
     *                         record on this.
     * @param  int  $amountCents  Charge amount in minor units.
     * @param  string  $reference  OUR reference (Payment::generateReference), so the
     *                             transaction can be reconciled from either side.
     * @param  string  $callbackUrl  Where the PSP returns the browser afterwards.
     * @param  string|null  $planCode  PSP plan code to start a recurring
     *                                 subscription rather than a one-off charge.
     * @param  array<string, mixed>  $metadata  Echoed back on verification and in
     *                                          webhooks; we use it to carry the
     *                                          organisation and plan ids.
     *
     * @throws \Modules\Billing\Exceptions\GatewayException
     */
    public function initialiseTransaction(
        string $email,
        int $amountCents,
        string $reference,
        string $callbackUrl,
        ?string $planCode = null,
        array $metadata = [],
    ): GatewayTransaction;

    /**
     * Confirm a transaction's real outcome, server-to-server.
     *
     * This is the authoritative check. The browser callback only tells us the
     * customer came back — its query string is attacker-controlled and must never
     * be trusted to mark a payment successful.
     *
     * @param  string  $reference  Our reference, or the PSP's.
     *
     * @throws \Modules\Billing\Exceptions\GatewayException
     */
    public function verifyTransaction(string $reference): GatewayTransaction;

    /**
     * Create a recurring plan at the PSP, returning its code.
     *
     * Called when an admin saves a paid plan, so the PSP has a matching object to
     * bill against.
     *
     * @param  int  $amountCents  Minor units.
     * @param  string  $interval  'monthly' | 'annually'.
     *
     * @throws \Modules\Billing\Exceptions\GatewayException
     */
    public function createPlan(string $name, int $amountCents, string $interval, string $currency): GatewayPlan;

    /**
     * Stop a recurring subscription at the PSP.
     *
     * @param  string  $subscriptionCode  PSP subscription code.
     * @param  string  $emailToken  Token the PSP issued alongside it; Paystack
     *                              requires both to disable.
     *
     * @throws \Modules\Billing\Exceptions\GatewayException
     */
    public function disableSubscription(string $subscriptionCode, string $emailToken): void;

    /**
     * Verify a webhook payload actually came from the PSP.
     *
     * Implementations must compare using a timing-safe function — a plain
     * string comparison leaks how much of a forged signature was correct.
     *
     * @param  string  $rawBody  The unparsed request body. Must be the raw bytes:
     *                           decoding and re-encoding JSON changes them and the
     *                           signature will never match.
     * @param  string|null  $signature  Signature header value, absent on a forged
     *                                  request.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool;
}
