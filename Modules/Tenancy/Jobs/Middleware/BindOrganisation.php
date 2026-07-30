<?php

namespace Modules\Tenancy\Jobs\Middleware;

use Modules\Tenancy\Contracts\TenantAwareJob;
use Modules\Tenancy\Models\Organisation;
use Modules\Tenancy\Services\OrganisationContext;

/**
 * Job middleware that binds a job's organisation for the duration of its run,
 * then unbinds it.
 *
 * Add it from the job's middleware() method:
 *
 *     public function middleware(): array
 *     {
 *         return [new BindOrganisation];
 *     }
 *
 * The unbind in the finally block is the important half. Workers are long-lived
 * and OrganisationContext is a singleton, so leaving a tenant bound after a job
 * finishes means the *next* job — possibly for a different customer — starts
 * with the wrong organisation in scope. Clearing unconditionally, including
 * when the job throws, is what makes that impossible rather than unlikely.
 */
class BindOrganisation
{
    /**
     * @param  TenantAwareJob  $job  The job being processed.
     * @param  callable(TenantAwareJob): void  $next
     */
    public function handle(TenantAwareJob $job, callable $next): void
    {
        $context = app(OrganisationContext::class);

        // withTrashed: a job may legitimately still be in flight for an
        // organisation that was soft-deleted after dispatch (an export
        // finishing, a cleanup running). Failing to bind here would make the
        // job throw MissingOrganisationContextException instead of completing
        // or failing on its own terms.
        $organisation = Organisation::withTrashed()->find($job->organisationId());

        if ($organisation === null) {
            // Hard-deleted organisation: there is nothing to act on and no safe
            // tenant to bind. Fail the job rather than run it unscoped.
            throw new \RuntimeException(sprintf(
                'Job [%s] references organisation [%s], which no longer exists.',
                $job::class,
                $job->organisationId()
            ));
        }

        $context->set($organisation);

        try {
            $next($job);
        } finally {
            $context->forget();
        }
    }
}
