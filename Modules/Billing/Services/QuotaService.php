<?php

namespace Modules\Billing\Services;

use Modules\Billing\Exceptions\QuotaExceededException;
use Modules\Billing\Models\GenerationUsage;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\QuotaStatus;
use Modules\Tenancy\Models\Organisation;

/**
 * Generation metering: how much has been used, whether another run is allowed,
 * and recording consumption.
 *
 * This is the enforcement point for the commercial model, so a few things about
 * it are deliberate:
 *
 * **Usage is summed from the ledger, never counted on the subscription.** Two
 * concurrent generations that each read-then-increment a counter lose one of the
 * increments. Summing append-only rows cannot drift, and it makes any disputed
 * number auditable line by line.
 *
 * **Period rollover happens on read, not on a cron.** statusFor() notices an
 * elapsed window and rolls it. A scheduled job that silently stopped running
 * would freeze every customer's quota at last month's usage and lock out paying
 * accounts; doing it lazily means the failure mode is "nothing happens", not
 * "everyone is blocked".
 *
 * **A credit is consumed on success only.** recordUsage() is called after a
 * generation completes. Nobody pays a credit for our timeout or a provider
 * error.
 */
class QuotaService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Current allowance status for an organisation.
     *
     * Also performs two pieces of lazy maintenance, which is why this is the
     * single entry point everything else uses:
     *   - downgrades a subscription whose paid access has genuinely run out;
     *   - rolls the metering window forward when the period has elapsed.
     */
    public function statusFor(Organisation $organisation): QuotaStatus
    {
        $subscription = $this->subscriptions->currentFor($organisation);

        // No subscription at all. Shouldn't happen — every organisation is
        // provisioned onto free at creation — but it is reachable for data
        // created before Billing existed, so report a blocked state rather than
        // dereferencing null.
        if ($subscription === null) {
            return new QuotaStatus(
                used: 0,
                limit: 0,
                periodStart: now(),
                periodEnd: now(),
                planName: 'No plan',
                subscriptionUsable: false,
            );
        }

        // Lapsed beyond its grace period: drop to free and meter against that,
        // so the customer is limited rather than locked out.
        $downgraded = $this->subscriptions->downgradeIfElapsed($subscription);

        if ($downgraded !== null) {
            $subscription = $downgraded;
        }

        if ($subscription->periodHasElapsed()) {
            $subscription = $this->subscriptions->rollPeriod($subscription);
        }

        return new QuotaStatus(
            used: $this->usedInPeriod($subscription),
            limit: $subscription->generation_quota,
            periodStart: $subscription->current_period_start,
            periodEnd: $subscription->current_period_end,
            planName: $subscription->plan_name,
            subscriptionUsable: $subscription->isUsable(),
        );
    }

    /**
     * Assert that a generation may proceed.
     *
     * Call this from the service layer immediately before spending money on an
     * LLM call — not from a controller. Generation also runs from queued jobs and
     * the per-section regenerate path, and a controller-only check would leave
     * both unmetered.
     *
     * @throws QuotaExceededException Carrying the QuotaStatus, so the caller can
     *         show the customer their actual limit and an upgrade link.
     */
    public function assertCanGenerate(Organisation $organisation): QuotaStatus
    {
        $status = $this->statusFor($organisation);

        if (! $status->allowsGeneration()) {
            throw new QuotaExceededException($status);
        }

        return $status;
    }

    /**
     * Non-throwing variant, for deciding whether to render a button.
     */
    public function canGenerate(Organisation $organisation): bool
    {
        return $this->statusFor($organisation)->allowsGeneration();
    }

    /**
     * Record a consumed credit after a successful generation.
     *
     * The period is stamped from the subscription rather than derived from
     * `now()`, so the row stays attributed to the window it was spent in even if
     * the subscription's dates are adjusted afterwards.
     *
     * @param  string|null  $meetingId  What was generated — makes the ledger
     *                                  auditable against real documents.
     * @param  string|null  $userId  Who ran it.
     * @param  string|null  $modelUsed  For measuring margin per plan.
     * @param  int|null  $tokensUsed  Likewise.
     * @param  int  $credits  Almost always 1; the column exists so a future
     *                        "long transcript costs more" rule needs no migration.
     */
    public function recordUsage(
        Organisation $organisation,
        ?string $meetingId = null,
        ?string $userId = null,
        ?string $modelUsed = null,
        ?int $tokensUsed = null,
        int $credits = 1,
    ): ?GenerationUsage {
        $subscription = $this->subscriptions->currentFor($organisation);

        if ($subscription === null) {
            // Nothing to meter against. The generation already succeeded, so
            // throwing here would fail a request whose work is done and would lose
            // the customer their minutes — log-and-continue is the right trade.
            report(new \RuntimeException(
                "Recorded a generation for organisation [{$organisation->getKey()}] with no live subscription."
            ));

            return null;
        }

        return GenerationUsage::query()->create([
            'organisation_id' => $organisation->getKey(),
            'subscription_id' => $subscription->getKey(),
            'meeting_id' => $meetingId,
            'user_id' => $userId,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'credits' => $credits,
            'model_used' => $modelUsed,
            'tokens_used' => $tokensUsed,
        ]);
    }

    /**
     * Credits consumed inside a subscription's current window.
     *
     * withoutOrganisationScope with an explicit organisation_id filter: this runs
     * from queued jobs and webhook handlers where no organisation is bound to the
     * context, and the filter is already stated, so the global scope would only
     * throw.
     *
     * Coalesced to 0 because SUM over no rows returns null, and null would break
     * the arithmetic in QuotaStatus.
     */
    private function usedInPeriod(Subscription $subscription): int
    {
        return (int) GenerationUsage::withoutOrganisationScope()
            ->where('organisation_id', $subscription->organisation_id)
            ->where('period_start', $subscription->current_period_start)
            ->sum('credits');
    }
}
