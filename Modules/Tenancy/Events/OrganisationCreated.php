<?php

namespace Modules\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Tenancy\Models\Organisation;

/**
 * Fired once, immediately after a new organisation and its owner membership
 * have been committed.
 *
 * This is the seam between Tenancy and Billing. Tenancy knows nothing about
 * plans or Paystack; Billing listens for this and provisions the free
 * subscription (see Billing's ProvisionFreeSubscription listener). Wiring it
 * as an event rather than a direct service call is what lets Tenancy boot and
 * be tested with the Billing module absent entirely — which matters because
 * Tenancy is priority 5 and Billing is priority 25.
 *
 * Anything else that should happen for a brand-new workspace (a welcome email,
 * an analytics ping) hangs off this same event instead of being bolted into the
 * registration controller.
 */
class OrganisationCreated
{
    use Dispatchable;

    public function __construct(public readonly Organisation $organisation) {}
}
