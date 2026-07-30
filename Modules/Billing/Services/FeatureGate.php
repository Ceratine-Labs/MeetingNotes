<?php

namespace Modules\Billing\Services;

use Modules\Tenancy\Models\Organisation;

/**
 * Answers "does this organisation's plan include feature X?"
 *
 * Feature flags live in the `features` JSON on the plan, snapshotted onto the
 * subscription. Keeping them as data rather than code means a new tier, or moving
 * DOCX export from Team down to Starter, is an admin edit rather than a deploy.
 *
 * Like everything entitlement-related, reads come from the subscription snapshot,
 * not the live plan.
 */
class FeatureGate
{
    /**
     * Export formats the plan allows, as a list of extensions.
     *
     * Markdown is the floor for everyone, including free: a customer must always
     * be able to get their own minutes out of the product. Locking every export
     * behind a paid tier would make the free plan a data roach motel, which is
     * both hostile and, under POPIA/GDPR data-portability rules, legally
     * awkward.
     */
    public const EXPORTS = 'exports';

    /**
     * May the organisation edit the generation prompt templates?
     */
    public const CUSTOM_PROMPTS = 'custom_prompts';

    /**
     * May the organisation use the HTTP API?
     */
    public const API = 'api';

    /**
     * Export formats always available, whatever the plan says.
     *
     * @var list<string>
     */
    public const BASELINE_EXPORTS = ['md'];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Is a boolean feature enabled?
     *
     * @param  string  $feature  One of the self::* keys.
     */
    public function allows(Organisation $organisation, string $feature): bool
    {
        $subscription = $this->subscriptions->currentFor($organisation);

        if ($subscription === null) {
            return false;
        }

        return (bool) $subscription->feature($feature, false);
    }

    /**
     * Export formats available to this organisation.
     *
     * The baseline is always merged in, so a plan whose `features` JSON is empty
     * or malformed still permits markdown rather than trapping the customer's
     * data.
     *
     * @return list<string> Lowercase extensions, e.g. ['md', 'docx', 'pdf'].
     */
    public function exportFormats(Organisation $organisation): array
    {
        $subscription = $this->subscriptions->currentFor($organisation);
        $configured = $subscription?->feature(self::EXPORTS, []) ?? [];

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_values(array_unique(array_merge(
            self::BASELINE_EXPORTS,
            array_map(
                static fn (mixed $format): string => mb_strtolower((string) $format),
                $configured
            )
        )));
    }

    /**
     * May this organisation export in a given format?
     *
     * @param  string  $format  Extension, e.g. 'pdf'. Case-insensitive.
     */
    public function allowsExport(Organisation $organisation, string $format): bool
    {
        return in_array(mb_strtolower($format), $this->exportFormats($organisation), true);
    }
}
