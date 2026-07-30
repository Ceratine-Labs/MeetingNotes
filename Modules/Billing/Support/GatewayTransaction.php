<?php

namespace Modules\Billing\Support;

/**
 * A payment-provider transaction, normalised into our own shape.
 *
 * Exists so that raw Paystack JSON never travels beyond the gateway class.
 * Services and controllers read typed properties, which means swapping or adding
 * a PSP does not ripple through every caller, and a change to Paystack's response
 * shape breaks in exactly one place.
 *
 * Immutable (readonly): a verified transaction is a statement of fact about
 * something that already happened, and nothing downstream should be able to edit
 * it before writing it to the payments table.
 */
readonly class GatewayTransaction
{
    /**
     * @param  bool  $successful  The charge completed. Only ever true when it came
     *                            from a server-side verification.
     * @param  string  $reference  Our reference.
     * @param  string|null  $providerReference  The PSP's own reference.
     * @param  int  $amountCents  Amount actually charged, in minor units. Compare
     *                            against what was expected — a mismatch means
     *                            tampering or a currency mix-up.
     * @param  string|null  $authorizationUrl  Where to redirect the customer.
     *                                         Present on initialise, absent on
     *                                         verify.
     * @param  string|null  $customerCode  PSP customer code, for later charges.
     * @param  string|null  $subscriptionCode  Set when the charge started a
     *                                         recurring subscription.
     * @param  string|null  $emailToken  Needed together with the subscription code
     *                                   to cancel at Paystack.
     * @param  string|null  $channel  card, bank, eft, ussd…
     * @param  string|null  $cardLast4  Last four digits only. Never store or log
     *                                  more of a card number than this.
     * @param  string|null  $cardBrand  visa, mastercard…
     * @param  string|null  $failureReason  Provider's message when unsuccessful.
     * @param  array<string, mixed>  $raw  The full decoded payload, persisted to
     *                                     payments.provider_payload as dispute
     *                                     evidence.
     */
    public function __construct(
        public bool $successful,
        public string $reference,
        public ?string $providerReference = null,
        public int $amountCents = 0,
        public ?string $authorizationUrl = null,
        public ?string $customerCode = null,
        public ?string $subscriptionCode = null,
        public ?string $emailToken = null,
        public ?string $channel = null,
        public ?string $cardLast4 = null,
        public ?string $cardBrand = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
