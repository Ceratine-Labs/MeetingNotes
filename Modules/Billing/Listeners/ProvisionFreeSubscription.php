<?php

namespace Modules\Billing\Listeners;

use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Events\OrganisationCreated;

/**
 * Puts every new organisation on the free plan.
 *
 * This listener is the whole seam between Tenancy and Billing. Tenancy creates a
 * workspace and knows nothing about plans; this reacts and provisions the
 * entitlement. Because the coupling is an event, Tenancy boots and tests fine
 * with Billing absent — which matters, since Tenancy is priority 5 and Billing
 * is 25.
 *
 * Runs **synchronously**, not queued. A workspace must have a subscription by the
 * time the registration redirect lands, or the customer's first page load hits a
 * quota check with no plan behind it. This is one small insert; there is nothing
 * to gain by deferring it and a visible bug if it arrives late.
 *
 * provisionFree() is idempotent, so a replayed or duplicated event cannot create
 * a second subscription — or, worse, downgrade a customer who has since upgraded.
 */
class ProvisionFreeSubscription
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(OrganisationCreated $event): void
    {
        $this->subscriptions->provisionFree($event->organisation);
    }
}
