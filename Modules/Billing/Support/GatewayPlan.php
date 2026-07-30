<?php

namespace Modules\Billing\Support;

/**
 * A recurring plan as it exists at the payment provider.
 *
 * Returned by PaymentGateway::createPlan(); the only field we persist is the
 * code, onto plans.paystack_plan_code, which is what subsequent subscriptions
 * are billed against.
 */
readonly class GatewayPlan
{
    /**
     * @param  string  $code  Provider plan code (Paystack: PLN_xxxxxxxx).
     * @param  string  $name  Name as recorded at the provider.
     * @param  int  $amountCents  Recurring amount in minor units.
     * @param  string  $interval  'monthly' | 'annually'.
     * @param  array<string, mixed>  $raw  Full decoded payload, for debugging a
     *                                     mismatch between our plan and theirs.
     */
    public function __construct(
        public string $code,
        public string $name,
        public int $amountCents,
        public string $interval,
        public array $raw = [],
    ) {}
}
