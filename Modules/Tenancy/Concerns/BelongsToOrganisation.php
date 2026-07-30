<?php

namespace Modules\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Organisation;
use Modules\Tenancy\Scopes\OrganisationScope;
use Modules\Tenancy\Services\OrganisationContext;

/**
 * Makes a model tenant-owned: every query is filtered to the current
 * organisation, and every insert is stamped with it.
 *
 * Apply to any model whose table has an `organisation_id` column. Models that
 * are reached only through a tenant-owned parent (transcripts and decisions
 * hang off a meeting) deliberately do NOT use this — one scoped aggregate root
 * per object graph is enough, and duplicating organisation_id down the tree
 * creates rows that can disagree with their parent.
 *
 * Usage:
 *
 *     class Meeting extends BaseModel
 *     {
 *         use BelongsToOrganisation;
 *     }
 *
 * Escaping the scope is possible but must always be deliberate and obvious at
 * the call site — that is what `withoutOrganisationScope()` is for. It exists
 * for the admin back office (which legitimately reports across all tenants)
 * and for maintenance commands. If you reach for it anywhere else, the answer
 * is almost certainly to bind the right organisation instead.
 */
trait BelongsToOrganisation
{
    /**
     * Register the global scope and the creating hook.
     *
     * Laravel calls this automatically for any trait named booted{TraitName}.
     */
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new OrganisationScope);

        static::creating(function (self $model): void {
            // Only fill when the caller has not set it explicitly. An explicit
            // organisation_id is how the admin back office and data-repair
            // scripts write on behalf of a tenant, and silently overwriting it
            // would make those writes land in the wrong organisation.
            if (! empty($model->organisation_id)) {
                return;
            }

            $context = app(OrganisationContext::class);

            if ($context->has()) {
                $model->organisation_id = $context->id();
            }
        });
    }

    /**
     * The owning organisation.
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    /**
     * Query across every organisation.
     *
     * Intended for the admin back office and console commands only. Naming it
     * verbosely is intentional: a reader of the calling code should see
     * immediately that tenant isolation has been switched off on purpose.
     */
    public static function withoutOrganisationScope(): Builder
    {
        return static::query()->withoutGlobalScope(OrganisationScope::class);
    }
}
