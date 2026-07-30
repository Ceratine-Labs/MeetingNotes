<?php

namespace Modules\Tenancy\Contracts;

/**
 * Marks a queued job that operates on one organisation's data.
 *
 * Implement this on any job that touches a tenant-owned model, and add the
 * BindOrganisation job middleware. Together they guarantee the job runs with the
 * right organisation bound to OrganisationContext.
 *
 * This matters more in a worker than in a web request. A queue worker is a
 * long-lived process handling jobs for many customers back to back, and
 * OrganisationContext is a singleton — so a job that does not set the context
 * inherits whatever the *previous* job left behind. That is a cross-tenant data
 * leak with no HTTP request anywhere near it, and the kind of bug that looks
 * fine in every test that runs one job at a time.
 */
interface TenantAwareJob
{
    /**
     * UUID of the organisation this job acts for.
     *
     * Must come from data captured when the job was dispatched (a model's
     * organisation_id), never from anything a user could influence at run time.
     */
    public function organisationId(): string;
}
