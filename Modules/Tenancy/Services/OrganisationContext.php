<?php

namespace Modules\Tenancy\Services;

use Modules\Tenancy\Models\Organisation;

/**
 * Holds the organisation the current execution is acting on behalf of.
 *
 * Every tenant-owned model filters its queries through this (see the
 * BelongsToOrganisation trait), which makes it the single most
 * security-relevant object in the codebase. Two rules follow from that:
 *
 *   1. **The scope only applies when an organisation is set.** An unset
 *      context does not mean "show everything" by accident — it means the
 *      caller has not declared who they are, and the BelongsToOrganisation
 *      trait refuses to run unscoped in a web request (it throws). Console,
 *      queue and admin code paths legitimately run without an organisation
 *      and must opt in explicitly.
 *
 *   2. **Never infer the organisation from user input.** It is resolved from
 *      the session or the user's own row by OrganisationResolver, or set
 *      explicitly by a queued job that already validated ownership. A route
 *      parameter or form field must never reach set().
 *
 * Registered as a singleton, so it is per-request in web and per-process in
 * a queue worker — which is exactly why jobs have to set it themselves
 * (forget(), then set) rather than inheriting whatever the previous job left.
 */
class OrganisationContext
{
    private ?Organisation $organisation = null;

    /**
     * Bind an organisation for the remainder of this request or job.
     */
    public function set(Organisation $organisation): void
    {
        $this->organisation = $organisation;
    }

    /**
     * Clear the bound organisation.
     *
     * Queue workers are long-lived and process jobs for many organisations in
     * one process. A job MUST clear (or overwrite) the context so it can never
     * inherit the previous job's tenant — that would be a cross-tenant data
     * leak, so the framework-level place to do this is a job middleware, not
     * hope.
     */
    public function forget(): void
    {
        $this->organisation = null;
    }

    /**
     * The bound organisation, or null when none has been set.
     */
    public function get(): ?Organisation
    {
        return $this->organisation;
    }

    /**
     * The bound organisation's UUID, or null.
     */
    public function id(): ?string
    {
        return $this->organisation?->getKey();
    }

    /**
     * Is an organisation bound?
     */
    public function has(): bool
    {
        return $this->organisation !== null;
    }

    /**
     * The bound organisation, or fail loudly.
     *
     * Use this anywhere the code cannot meaningfully continue without a
     * tenant. A hard exception beats a silent null that turns into an
     * unscoped query further down the stack.
     *
     * @throws \RuntimeException
     */
    public function getOrFail(): Organisation
    {
        if ($this->organisation === null) {
            throw new \RuntimeException(
                'No organisation is bound to this context. A web request should '
                .'have passed through the EnsureOrganisation middleware; a queued '
                .'job must bind one explicitly before touching tenant data.'
            );
        }

        return $this->organisation;
    }

    /**
     * Run a callback with a specific organisation bound, then restore whatever
     * was bound before.
     *
     * The restore is in a finally block on purpose: if the callback throws, the
     * previous tenant must still be put back, or the rest of the request runs
     * as the wrong organisation.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organisation $organisation, callable $callback): mixed
    {
        $previous = $this->organisation;
        $this->organisation = $organisation;

        try {
            return $callback();
        } finally {
            $this->organisation = $previous;
        }
    }
}
