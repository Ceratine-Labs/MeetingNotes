<?php

namespace Modules\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Tenancy\Exceptions\MissingOrganisationContextException;
use Modules\Tenancy\Services\OrganisationContext;

/**
 * Global scope that confines every query on a tenant-owned model to the
 * organisation currently bound to OrganisationContext.
 *
 * The interesting part is what happens when NO organisation is bound, because
 * that is where a tenancy bug turns into a data leak. There are two honest
 * situations and this scope treats them differently:
 *
 *   * **A web request with no bound organisation is a bug.** The
 *     EnsureOrganisation middleware runs on every tenant route and binds one,
 *     so reaching a query without it means a route was registered outside that
 *     middleware. Returning unfiltered rows there would quietly serve one
 *     customer another customer's minutes, so we throw instead. Loud failure
 *     in staging beats a silent breach in production.
 *
 *   * **Console, queue and test code legitimately run without one.** Migrations,
 *     the seed master, backup jobs and admin reporting all operate across
 *     tenants by nature. There the scope stands down and the query runs
 *     unfiltered — the caller is trusted server-side code, not a browser.
 *
 * Either way, the escape hatch for deliberate cross-tenant reads is
 * `Model::withoutOrganisationScope()`, which says so at the call site.
 */
class OrganisationScope implements Scope
{
    /**
     * Constrain the query to the bound organisation.
     *
     * @throws MissingOrganisationContextException When a browser request
     *         reaches tenant data with no organisation bound.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OrganisationContext::class);

        if ($context->has()) {
            // Qualified column name — an unqualified organisation_id is
            // ambiguous the moment this query gains a join.
            $builder->where($model->qualifyColumn('organisation_id'), $context->id());

            return;
        }

        if ($this->runningOutsideWebRequest()) {
            return;
        }

        throw new MissingOrganisationContextException(sprintf(
            'Query on tenant-owned model [%s] with no organisation bound. The route '
            .'is probably missing the "organisation" middleware. If this query is '
            .'meant to span tenants, call %s::withoutOrganisationScope() explicitly.',
            $model::class,
            class_basename($model)
        ));
    }

    /**
     * Are we somewhere that is allowed to read across tenants?
     *
     * Console covers artisan commands, the seed master and queue workers;
     * testing covers factories and assertions that set up fixtures before any
     * organisation exists.
     */
    private function runningOutsideWebRequest(): bool
    {
        return app()->runningInConsole() || app()->environment('testing');
    }
}
